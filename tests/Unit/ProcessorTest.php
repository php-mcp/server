<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\ClientStateManager;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\JsonRpc\Error as JsonRpcError;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\CallToolResult;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\JsonRpc\Results\InitializeResult;
use PhpMcp\Server\JsonRpc\Results\ListToolsResult;
use PhpMcp\Server\Model\Capabilities;
use PhpMcp\Server\Processor;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Support\ArgumentPreparer;
use PhpMcp\Server\Support\SchemaValidator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Loop;
use stdClass;

const CLIENT_ID_PROC = 'test-proc-client-456';
const SUPPORTED_VERSION_PROC = '2024-11-05';
const SERVER_NAME_PROC = 'Test Proc Server';
const SERVER_VERSION_PROC = '0.2.0';

/** Creates a Request object for testing */
function createRequest(string $method, array $params = [], string $id = 'req-1'): Request
{
    return new Request('2.0', $id, $method, $params);
}

/** Creates a Notification object for testing */
function createNotification(string $method, array $params = []): Notification
{
    return new Notification('2.0', $method, $params);
}

/** Asserts that a response is an MCP error response with the expected code */
function expectMcpErrorResponse(?Response $response, int $expectedCode, ?string $id = 'req-1'): void
{
    test()->expect($response)->toBeInstanceOf(Response::class);
    test()->expect($response->id)->toBe($id);
    test()->expect($response->result)->toBeNull();
    test()->expect($response->error)->toBeInstanceOf(JsonRpcError::class); // Use alias
    test()->expect($response->error->code)->toBe($expectedCode);
}

beforeEach(function () {
    $this->containerMock = Mockery::mock(ContainerInterface::class);
    $this->registryMock = Mockery::mock(Registry::class);
    $this->clientStateManagerMock = Mockery::mock(ClientStateManager::class);
    /** @var LoggerInterface&MockInterface $loggerMock */
    $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->schemaValidatorMock = Mockery::mock(SchemaValidator::class);
    $this->argumentPreparerMock = Mockery::mock(ArgumentPreparer::class);
    $this->cacheMock = Mockery::mock(CacheInterface::class);

    // Create a default Configuration object for tests
    $this->configuration = new Configuration(
        serverName: SERVER_NAME_PROC,
        serverVersion: SERVER_VERSION_PROC,
        capabilities: Capabilities::forServer(),
        logger: $this->loggerMock,
        loop: Loop::get(),
        cache: $this->cacheMock,
        container: $this->containerMock,
        definitionCacheTtl: 3600
    );

    // Default registry state (empty)
    $this->registryMock->allows('allTools')->withNoArgs()->andReturn(new \ArrayObject())->byDefault();
    $this->registryMock->allows('allResources')->withNoArgs()->andReturn(new \ArrayObject())->byDefault();
    $this->registryMock->allows('allResourceTemplates')->withNoArgs()->andReturn(new \ArrayObject())->byDefault();
    $this->registryMock->allows('allPrompts')->withNoArgs()->andReturn(new \ArrayObject())->byDefault();

    // Default transport state (not initialized)
    $this->clientStateManagerMock->allows('isInitialized')->with(CLIENT_ID_PROC)->andReturn(false)->byDefault();

    $this->processor = new Processor(
        $this->configuration,
        $this->registryMock,
        $this->clientStateManagerMock,
        $this->containerMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock
    );
});

// createRequest, createNotification, expectMcpErrorResponse helpers remain the same

// --- Tests Start Here ---

test('constructor receives dependencies', function () {
    expect($this->processor)->toBeInstanceOf(Processor::class);
});

// --- Initialize Tests (Updated capabilities check) ---

test('handleInitialize succeeds with valid parameters', function () {
    $clientInfo = ['name' => 'TestClientProc', 'version' => '1.3.0'];
    $request = createRequest('initialize', [
        'protocolVersion' => SUPPORTED_VERSION_PROC,
        'clientInfo' => $clientInfo,
    ]);

    $this->clientStateManagerMock->shouldReceive('storeClientInfo')->once()->with($clientInfo, SUPPORTED_VERSION_PROC, CLIENT_ID_PROC);

    // Mock registry counts to enable capabilities in response
    $this->registryMock->allows('allTools')->andReturn(new \ArrayObject(['dummyTool' => new stdClass()]));
    $this->registryMock->allows('allResources')->andReturn(new \ArrayObject(['dummyRes' => new stdClass()]));
    $this->registryMock->allows('allPrompts')->andReturn(new \ArrayObject(['dummyPrompt' => new stdClass()]));

    // Override default capabilities in the configuration passed to processor for this test
    $capabilities = Capabilities::forServer(
        toolsEnabled: true,
        toolsListChanged: true,
        resourcesEnabled: true,
        resourcesSubscribe: true,
        resourcesListChanged: false,
        promptsEnabled: true,
        promptsListChanged: true,
        loggingEnabled: true,
        instructions: 'Test Instructions'
    );
    $this->configuration = new Configuration(
        serverName: SERVER_NAME_PROC,
        serverVersion: SERVER_VERSION_PROC,
        capabilities: $capabilities,
        logger: $this->loggerMock,
        loop: Loop::get(),
        cache: $this->cacheMock,
        container: $this->containerMock
    );
    // Re-create processor with updated config for this test
    $this->processor = new Processor($this->configuration, $this->registryMock, $this->clientStateManagerMock, $this->containerMock, $this->schemaValidatorMock, $this->argumentPreparerMock);

    /** @var Response<InitializeResult> $response */
    $response = $this->processor->process($request, CLIENT_ID_PROC);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->id)->toBe($request->id);
    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(InitializeResult::class);
    expect($response->result->serverInfo['name'])->toBe(SERVER_NAME_PROC);
    expect($response->result->serverInfo['version'])->toBe(SERVER_VERSION_PROC);
    expect($response->result->protocolVersion)->toBe(SUPPORTED_VERSION_PROC);
    expect($response->result->capabilities)->toHaveKeys(['tools', 'resources', 'prompts', 'logging']);
    expect($response->result->capabilities['tools'])->toEqual(['listChanged' => true]);
    expect($response->result->capabilities['resources'])->toEqual(['subscribe' => true]);
    expect($response->result->capabilities['prompts'])->toEqual(['listChanged' => true]);
    expect($response->result->capabilities['logging'])->toBeInstanceOf(stdClass::class);
    expect($response->result->instructions)->toBe('Test Instructions');
});

// Other initialize tests (missing params, etc.) remain largely the same logic

test('handleNotificationInitialized marks client as initialized and returns null', function () {
    $notification = createNotification('notifications/initialized');
    $this->clientStateManagerMock->shouldReceive('markInitialized')->once()->with(CLIENT_ID_PROC);
    $response = $this->processor->process($notification, CLIENT_ID_PROC);
    expect($response)->toBeNull();
});

test('process fails if client not initialized for non-initialize methods', function (string $method) {
    $request = createRequest($method);
    $response = $this->processor->process($request, CLIENT_ID_PROC);
    expectMcpErrorResponse($response, McpServerException::CODE_INVALID_REQUEST); // Check correct code
    expect($response->error->message)->toContain('Client not initialized');
})->with([
    'tools/list', 'tools/call', 'resources/list', // etc.
]);

test('process fails if capability is disabled', function (string $method, array $params, array $enabledCaps) {
    $this->clientStateManagerMock->allows('isInitialized')->with(CLIENT_ID_PROC)->andReturn(true);

    $capabilities = Capabilities::forServer(...$enabledCaps);
    $this->configuration = new Configuration(
        serverName: SERVER_NAME_PROC,
        serverVersion: SERVER_VERSION_PROC,
        capabilities: $capabilities,
        logger: $this->loggerMock,
        loop: Loop::get(),
        cache: $this->cacheMock,
        container: $this->containerMock
    );
    $this->processor = new Processor($this->configuration, $this->registryMock, $this->clientStateManagerMock, $this->containerMock, $this->schemaValidatorMock, $this->argumentPreparerMock);

    $request = createRequest($method, $params);
    $response = $this->processor->process($request, CLIENT_ID_PROC);

    expectMcpErrorResponse($response, McpServerException::CODE_METHOD_NOT_FOUND);
    expect($response->error->message)->toContain('capability');
    expect($response->error->message)->toContain('is not enabled');

})->with([
    'tools/call' => ['tools/call', [], ['toolsEnabled' => false]],
    'resources/read' => ['resources/read', [], ['resourcesEnabled' => false]],
    'resources/subscribe' => ['resources/subscribe', ['uri' => 'https://example.com/resource'], ['resourcesSubscribe' => false]],
    'resources/templates/list' => ['resources/templates/list', [], ['resourcesEnabled' => false]],
    'prompts/list' => ['prompts/list', [], ['promptsEnabled' => false]],
    'prompts/get' => ['prompts/get', [], ['promptsEnabled' => false]],
    'logging/setLevel' => ['logging/setLevel', [], ['loggingEnabled' => false]],
]);

test('handlePing succeeds for initialized client', function () {
    $this->clientStateManagerMock->allows('isInitialized')->with(CLIENT_ID_PROC)->andReturn(true);
    $request = createRequest('ping');
    $response = $this->processor->process($request, CLIENT_ID_PROC);
    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(EmptyResult::class);
});

test('handleToolList returns tools using hardcoded limit', function () {
    $this->clientStateManagerMock->allows('isInitialized')->with(CLIENT_ID_PROC)->andReturn(true);
    $tool1 = new ToolDefinition('Class', 'm1', 'tool1', 'd1', []);
    $tool2 = new ToolDefinition('Class', 'm2', 'tool2', 'd2', []);
    $this->registryMock->allows('allTools')->andReturn(new \ArrayObject([$tool1, $tool2]));

    $request = createRequest('tools/list');
    $response = $this->processor->process($request, CLIENT_ID_PROC);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListToolsResult::class);
    expect($response->result->tools)->toHaveCount(2); // Assumes limit >= 2
});

// Other list tests (empty, pagination) remain similar logic, just limit is fixed

// --- Action Methods (Unchanged Logic, Container Usage Verified) ---

test('handleToolCall uses container to get handler', function () {
    $this->clientStateManagerMock->allows('isInitialized')->with(CLIENT_ID_PROC)->andReturn(true);
    $toolName = 'myTool';
    $handlerClass = 'App\\Handlers\\MyToolHandler';
    $handlerMethod = 'execute';
    $rawArgs = ['p' => 'v'];
    $toolResult = 'Success';
    $definition = Mockery::mock(ToolDefinition::class);
    $handlerInstance = Mockery::mock($handlerClass);

    $definition->allows('getClassName')->andReturn($handlerClass);
    $definition->allows('getMethodName')->andReturn($handlerMethod);
    $definition->allows('getInputSchema')->andReturn([]);

    $this->registryMock->shouldReceive('findTool')->once()->with($toolName)->andReturn($definition);
    $this->schemaValidatorMock->shouldReceive('validateAgainstJsonSchema')->once()->andReturn([]);
    // *** Assert container is used ***
    $this->containerMock->shouldReceive('get')->once()->with($handlerClass)->andReturn($handlerInstance);
    // *******************************
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')->once()->andReturn(['v']);
    $handlerInstance->shouldReceive($handlerMethod)->once()->with('v')->andReturn($toolResult);

    // Spy/mock formatToolResult
    /** @var Processor&MockInterface $processorSpy */
    $processorSpy = Mockery::mock(Processor::class.'[formatToolResult]', [
        $this->configuration, $this->registryMock, $this->clientStateManagerMock, $this->containerMock,
        $this->schemaValidatorMock, $this->argumentPreparerMock,
    ])->makePartial()->shouldAllowMockingProtectedMethods();
    $processorSpy->shouldReceive('formatToolResult')->once()->andReturn([new TextContent('Success')]);

    $request = createRequest('tools/call', ['name' => $toolName, 'arguments' => $rawArgs]);
    $response = $processorSpy->process($request, CLIENT_ID_PROC);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(CallToolResult::class);
});

// Other action tests (call errors, read resource, get prompt, subscribe) remain similar logic,
// ensuring $this->container->get() is mocked correctly for handler resolution.

// Test subscribe/logging capability checks (if flags were added to Configuration VO)
// test('handleResourceSubscribe fails if capability flag false', function() { ... });
// test('handleLoggingSetLevel fails if capability flag false', function() { ... });
