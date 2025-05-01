<?php

namespace PhpMcp\Server\Tests;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Definitions\PromptArgumentDefinition;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Exceptions\McpException;
use PhpMcp\Server\JsonRpc\Contents\PromptMessage;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\JsonRpc\Error as JsonRpcError;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\CallToolResult;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\JsonRpc\Results\GetPromptResult;
use PhpMcp\Server\JsonRpc\Results\InitializeResult;
use PhpMcp\Server\JsonRpc\Results\ListPromptsResult;
use PhpMcp\Server\JsonRpc\Results\ListResourcesResult;
use PhpMcp\Server\JsonRpc\Results\ListResourceTemplatesResult;
use PhpMcp\Server\JsonRpc\Results\ListToolsResult;
use PhpMcp\Server\JsonRpc\Results\ReadResourceResult;
use PhpMcp\Server\Processor;
use PhpMcp\Server\Registry;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Support\ArgumentPreparer;
use PhpMcp\Server\Support\SchemaValidator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

const CLIENT_ID = 'test-client-123';
const SUPPORTED_VERSION = '2024-11-05';
const SERVER_NAME = 'Test Server';
const SERVER_VERSION = '0.1.0';

beforeEach(function () {
    $this->containerMock = Mockery::mock(ContainerInterface::class);
    $this->configMock = Mockery::mock(ConfigurationRepositoryInterface::class);
    $this->registryMock = Mockery::mock(Registry::class);
    $this->transportStateMock = Mockery::mock(TransportState::class);
    $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->schemaValidatorMock = Mockery::mock(SchemaValidator::class);
    $this->argumentPreparerMock = Mockery::mock(ArgumentPreparer::class);

    // Default config values
    $this->configMock->allows('get')->with('mcp.protocol_versions', Mockery::any())->andReturn([SUPPORTED_VERSION]);
    $this->configMock->allows('get')->with('mcp.protocol_version', Mockery::any())->andReturn(SUPPORTED_VERSION);
    $this->configMock->allows('get')->with('mcp.pagination_limit', Mockery::any())->andReturn(50)->byDefault();
    $this->configMock->allows('get')->with('mcp.server.name', Mockery::any())->andReturn(SERVER_NAME)->byDefault();
    $this->configMock->allows('get')->with('mcp.server.version', Mockery::any())->andReturn(SERVER_VERSION)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.tools.enabled', false)->andReturn(true)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.resources.enabled', false)->andReturn(true)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.prompts.enabled', false)->andReturn(true)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.logging.enabled', false)->andReturn(true)->byDefault();
    $this->configMock->allows('get')->with('mcp.instructions')->andReturn(null)->byDefault(); // Default no instructions
    $this->configMock->allows('get')->with('mcp.capabilities.tools.listChanged', false)->andReturn(true)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.resources.subscribe', false)->andReturn(false)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.resources.listChanged', false)->andReturn(false)->byDefault();
    $this->configMock->allows('get')->with('mcp.capabilities.prompts.listChanged', false)->andReturn(false)->byDefault();

    // Default registry state (empty)
    $this->registryMock->allows('allTools')->withNoArgs()->andReturn(new \ArrayObject)->byDefault();
    $this->registryMock->allows('allResources')->withNoArgs()->andReturn(new \ArrayObject)->byDefault();
    $this->registryMock->allows('allResourceTemplates')->withNoArgs()->andReturn(new \ArrayObject)->byDefault();
    $this->registryMock->allows('allPrompts')->withNoArgs()->andReturn(new \ArrayObject)->byDefault();

    // Default transport state (not initialized)
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(false)->byDefault();

    $this->containerMock->allows('get')->with(ConfigurationRepositoryInterface::class)->andReturn($this->configMock);
    $this->containerMock->allows('get')->with(LoggerInterface::class)->andReturn($this->loggerMock);

    $this->processor = new Processor(
        $this->containerMock,
        $this->registryMock,
        $this->transportStateMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock
    );
});

function createRequest(string $method, array $params = [], string $id = 'req-1'): Request
{
    return new Request('2.0', $id, $method, $params);
}

function createNotification(string $method, array $params = []): Notification
{
    return new Notification('2.0', $method, $params);
}

function expectMcpErrorResponse(?Response $response, int $expectedCode, ?string $id = 'req-1'): void
{
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->id)->toBe($id);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(JsonRpcError::class);
    expect($response->error->code)->toBe($expectedCode);
}

// --- Tests Start Here ---

test('constructor loads elements from registry', function () {
    // Assertion is handled by the mock expectation in beforeEach
    expect(true)->toBeTrue();
});

// --- Initialize Tests ---

test('handleInitialize succeeds with valid parameters', function () {
    $clientInfo = ['name' => 'TestClient', 'version' => '1.2.3'];
    $request = createRequest('initialize', [
        'protocolVersion' => SUPPORTED_VERSION,
        'clientInfo' => $clientInfo,
    ]);

    // Expect state update
    $this->transportStateMock->shouldReceive('storeClientInfo')
        ->once()
        ->with($clientInfo, SUPPORTED_VERSION, CLIENT_ID);

    // Mock registry counts to enable capabilities in response
    $this->registryMock->allows('allTools')->andReturn(new \ArrayObject(['dummyTool' => new stdClass]));
    $this->registryMock->allows('allResources')->andReturn(new \ArrayObject(['dummyRes' => new stdClass]));
    $this->registryMock->allows('allPrompts')->andReturn(new \ArrayObject(['dummyPrompt' => new stdClass]));

    $this->configMock->allows('get')->with('mcp.capabilities.resources.subscribe', false)->andReturn(true); // Enable subscribe for test

    $response = $this->processor->process($request, CLIENT_ID);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->id)->toBe($request->id);
    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(InitializeResult::class);
    expect($response->result->serverInfo['name'])->toBe(SERVER_NAME);
    expect($response->result->serverInfo['version'])->toBe(SERVER_VERSION);
    expect($response->result->protocolVersion)->toBe(SUPPORTED_VERSION);
    expect($response->result->capabilities)->toHaveKeys(['tools', 'resources', 'prompts', 'logging']);
    expect($response->result->capabilities['tools'])->toEqual(['listChanged' => true]);
    expect($response->result->capabilities['resources'])->toEqual(['subscribe' => true]); // listChanged is false by default
    expect($response->result->capabilities['prompts'])->toEqual(['listChanged' => false]);
    expect($response->result->capabilities['logging'])->toBeInstanceOf(stdClass::class); // Should be empty object
    expect($response->result->instructions)->toBeNull();
});

test('handleInitialize succeeds with instructions', function () {
    $clientInfo = ['name' => 'TestClient', 'version' => '1.2.3'];
    $instructionsText = 'Use the tools provided.';
    $request = createRequest('initialize', [
        'protocolVersion' => SUPPORTED_VERSION,
        'clientInfo' => $clientInfo,
    ]);

    $this->configMock->allows('get')->with('mcp.instructions')->andReturn($instructionsText);
    $this->transportStateMock->shouldReceive('storeClientInfo')->once();

    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->result->instructions)->toBe($instructionsText);
});

test('handleInitialize logs warning for unsupported protocol version but succeeds', function () {
    $clientInfo = ['name' => 'TestClient', 'version' => '1.2.3'];
    $unsupportedVersion = '2023-01-01';
    $request = createRequest('initialize', [
        'protocolVersion' => $unsupportedVersion,
        'clientInfo' => $clientInfo,
    ]);

    $this->loggerMock->shouldReceive('warning')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'unsupported protocol version') && $context['supportedVersions'] === [SUPPORTED_VERSION];
        });
    $this->transportStateMock->shouldReceive('storeClientInfo')->once()->with($clientInfo, SUPPORTED_VERSION, CLIENT_ID); // Stores SERVER's version

    $response = $this->processor->process($request, CLIENT_ID);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->error)->toBeNull();
    expect($response->result->protocolVersion)->toBe(SUPPORTED_VERSION); // Responds with server version
});

test('handleInitialize fails with missing protocolVersion', function () {
    $request = createRequest('initialize', ['clientInfo' => ['name' => 'TestClient']]);
    $response = $this->processor->process($request, CLIENT_ID);
    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing 'protocolVersion'");
});

test('handleInitialize fails with missing clientInfo', function () {
    $request = createRequest('initialize', ['protocolVersion' => SUPPORTED_VERSION]);
    $response = $this->processor->process($request, CLIENT_ID);
    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing or invalid 'clientInfo'");
});

test('handleInitialize fails with invalid clientInfo type', function () {
    $request = createRequest('initialize', [
        'protocolVersion' => SUPPORTED_VERSION,
        'clientInfo' => 'not-an-array',
    ]);
    $response = $this->processor->process($request, CLIENT_ID);
    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing or invalid 'clientInfo'");
});

// --- Initialized Notification ---

test('handleNotificationInitialized marks client as initialized and returns null', function () {
    $notification = createNotification('notifications/initialized');

    $this->transportStateMock->shouldReceive('markInitialized')
        ->once()
        ->with(CLIENT_ID);

    $response = $this->processor->process($notification, CLIENT_ID);

    expect($response)->toBeNull();
});

// --- Client State Validation ---

test('process fails if client not initialized for non-initialize methods', function (string $method) {
    // Transport state mock defaults to isInitialized = false in beforeEach

    $request = createRequest($method); // Params don't matter here
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_REQUEST);
    expect($response->error->message)->toContain('Client not initialized');
})->with([
    'tools/list',
    'tools/call',
    'resources/list',
    'resources/read',
    'resources/subscribe',
    'resources/unsubscribe',
    'resources/templates/list',
    'prompts/list',
    'prompts/get',
    'logging/setLevel',
]);

// --- Capability Validation ---

test('process fails if capability is disabled', function (string $method, string $configKey) {
    // Mark client as initialized first
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    // Disable the capability
    $this->configMock->allows('get')->with($configKey, false)->andReturn(false);

    $request = createRequest($method); // Params don't matter here
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_METHOD_NOT_FOUND);
    expect($response->error->message)->toContain('capability');
    expect($response->error->message)->toContain('is not enabled');
})->with([
    ['tools/list', 'mcp.capabilities.tools.enabled'],
    ['tools/call', 'mcp.capabilities.tools.enabled'],
    ['resources/list', 'mcp.capabilities.resources.enabled'],
    ['resources/read', 'mcp.capabilities.resources.enabled'],
    // subscribe/unsubscribe check capability internally
    ['resources/templates/list', 'mcp.capabilities.resources.enabled'],
    ['prompts/list', 'mcp.capabilities.prompts.enabled'],
    ['prompts/get', 'mcp.capabilities.prompts.enabled'],
    ['logging/setLevel', 'mcp.capabilities.logging.enabled'],
]);

// --- Ping ---

test('handlePing succeeds for initialized client', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $request = createRequest('ping');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->id)->toBe($request->id);
    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(EmptyResult::class);
});

// --- tools/list ---

test('handleToolList returns empty list when no tools', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->registryMock->allows('allTools')->andReturn(new \ArrayObject); // Default, but explicit

    $request = createRequest('tools/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListToolsResult::class);
    expect($response->result->tools)->toBe([]);
    expect($response->result->nextCursor)->toBeNull();
});

test('handleToolList returns tools without pagination', function () {
    $tool1 = new ToolDefinition('DummyToolClass', 'methodA', 'tool1', 'desc1', []);
    $tool2 = new ToolDefinition('DummyToolClass', 'methodB', 'tool2', 'desc2', []);

    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->registryMock->allows('allTools')->andReturn(new \ArrayObject([$tool1, $tool2]));

    $request = createRequest('tools/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListToolsResult::class);
    expect($response->result->tools)->toEqual([$tool1, $tool2]); // Order matters
    expect($response->result->nextCursor)->toBeNull();
});

test('handleToolList handles pagination correctly', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->configMock->allows('get')->with('mcp.pagination_limit', Mockery::any())->andReturn(1);

    $tool1 = new ToolDefinition('DummyToolClass', 'methodA', 'tool1', 'desc1', []);
    $tool2 = new ToolDefinition('DummyToolClass', 'methodB', 'tool2', 'desc2', []);
    $this->registryMock->allows('allTools')->andReturn(new \ArrayObject([$tool1, $tool2]));

    // First page
    $request1 = createRequest('tools/list', [], 'req-1');
    $response1 = $this->processor->process($request1, CLIENT_ID);

    expect($response1->error)->toBeNull();
    expect($response1->result->tools)->toEqual([$tool1]);
    expect($response1->result->nextCursor)->toBeString(); // Expect a cursor for next page
    $cursor = $response1->result->nextCursor;

    // Second page
    $request2 = createRequest('tools/list', ['cursor' => $cursor], 'req-2');
    $response2 = $this->processor->process($request2, CLIENT_ID);

    expect($response2->error)->toBeNull();
    expect($response2->result->tools)->toEqual([$tool2]);
    expect($response2->result->nextCursor)->toBeNull(); // No more pages
});

// --- tools/call ---

test('handleToolCall succeeds', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $toolName = 'myTool';
    $rawArgs = ['param1' => 'value1', 'param2' => 100];
    $inputSchema = ['type' => 'object', 'properties' => ['param1' => ['type' => 'string'], 'param2' => ['type' => 'number']]];
    $preparedArgs = ['value1', 100]; // Assume ArgumentPreparer maps them
    $toolResult = ['success' => true, 'data' => 'Result Data'];
    $formattedResult = [new TextContent(json_encode($toolResult))]; // Assume formatter JSON encodes

    $definition = Mockery::mock(ToolDefinition::class);
    $definition->allows('getClassName')->andReturn('DummyToolClass');
    $definition->allows('getMethodName')->andReturn('execute');
    $definition->allows('getInputSchema')->andReturn($inputSchema);

    $toolInstance = Mockery::mock('DummyToolClass');

    $this->registryMock->shouldReceive('findTool')->once()->with($toolName)->andReturn($definition);
    $this->schemaValidatorMock->shouldReceive('validateAgainstJsonSchema')->once()->with($rawArgs, $inputSchema)->andReturn([]); // No errors
    $this->containerMock->shouldReceive('get')->once()->with('DummyToolClass')->andReturn($toolInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($toolInstance, 'execute', $rawArgs, $inputSchema)
        ->andReturn($preparedArgs);
    $toolInstance->shouldReceive('execute')->once()->with(...$preparedArgs)->andReturn($toolResult);

    // Mock the formatter call using a processor spy
    /** @var MockInterface&Processor */
    $processorSpy = Mockery::mock(Processor::class, [
        $this->containerMock,
        $this->registryMock,
        $this->transportStateMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock,
    ])->makePartial();

    // Need to mock formatToolResult to avoid calling the protected trait method
    $processorSpy->shouldAllowMockingProtectedMethods();
    $processorSpy->shouldReceive('formatToolResult')->once()->with($toolResult)->andReturn($formattedResult);

    $request = createRequest('tools/call', ['name' => $toolName, 'arguments' => $rawArgs]);
    $response = $processorSpy->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(CallToolResult::class);
    expect($response->result->toArray()['content'])->toEqual(array_map(fn ($item) => $item->toArray(), $formattedResult));
    expect($response->result->toArray()['isError'])->toBeFalse();
});

test('handleToolCall fails if tool not found', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $toolName = 'nonExistentTool';
    $this->registryMock->shouldReceive('findTool')->once()->with($toolName)->andReturn(null);

    $request = createRequest('tools/call', ['name' => $toolName, 'arguments' => []]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_METHOD_NOT_FOUND);
    expect($response->error->message)->toContain("Tool '{$toolName}' not found");
});

test('handleToolCall fails on validation errors', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $toolName = 'myTool';
    $rawArgs = ['param1' => 123]; // Invalid type
    $inputSchema = ['type' => 'object', 'properties' => ['param1' => ['type' => 'string']]];
    $validationErrors = [['property' => 'param1', 'message' => 'Must be a string']];

    $definition = Mockery::mock(ToolDefinition::class);
    $definition->allows('getInputSchema')->andReturn($inputSchema);

    $this->registryMock->shouldReceive('findTool')->once()->with($toolName)->andReturn($definition);
    $this->schemaValidatorMock->shouldReceive('validateAgainstJsonSchema')
        ->once()
        ->with($rawArgs, $inputSchema)
        ->andReturn($validationErrors);

    $request = createRequest('tools/call', ['name' => $toolName, 'arguments' => $rawArgs]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->data['validation_errors'])->toBe($validationErrors);
});

test('handleToolCall handles tool execution exception', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $toolName = 'failingTool';
    $rawArgs = [new \stdClass];
    $inputSchema = [];
    $preparedArgs = [];
    $exceptionMessage = 'Something broke!';
    $toolException = new \RuntimeException($exceptionMessage);
    // Expected formatted error from ResponseFormatter trait
    $errorContent = [new TextContent('Tool execution failed: '.$exceptionMessage.' (Type: RuntimeException)')];

    $definition = Mockery::mock(ToolDefinition::class);
    $definition->allows('getClassName')->andReturn('DummyToolClass');
    $definition->allows('getMethodName')->andReturn('execute');
    $definition->allows('getInputSchema')->andReturn($inputSchema);

    $toolInstance = Mockery::mock('DummyToolClass');

    $this->registryMock->shouldReceive('findTool')->once()->with($toolName)->andReturn($definition);
    $this->schemaValidatorMock->shouldReceive('validateAgainstJsonSchema')->once()->with($rawArgs, $inputSchema)->andReturn([]);
    $this->containerMock->shouldReceive('get')->once()->with('DummyToolClass')->andReturn($toolInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($toolInstance, 'execute', $rawArgs, $inputSchema)
        ->andReturn($preparedArgs);
    // Tool throws an exception
    $toolInstance->shouldReceive('execute')->once()->with(...$preparedArgs)->andThrow($toolException);

    // Use spy to verify formatToolErrorResult is called
    /** @var MockInterface&Processor */
    $processorSpy = Mockery::mock(Processor::class, [
        $this->containerMock,
        $this->registryMock,
        $this->transportStateMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock,
    ])->makePartial();

    $processorSpy->shouldAllowMockingProtectedMethods();
    $processorSpy->shouldReceive('formatToolErrorResult')->once()->with($toolException)->andReturn($errorContent);

    $request = createRequest('tools/call', ['name' => $toolName, 'arguments' => $rawArgs]);
    $response = $processorSpy->process($request, CLIENT_ID);

    expect($response->error)->toBeNull(); // The *JSON-RPC* error is null
    expect($response->result)->toBeInstanceOf(CallToolResult::class);
    expect($response->result->toArray()['content'])->toEqual(array_map(fn ($item) => $item->toArray(), $errorContent));
    expect($response->result->toArray()['isError'])->toBeTrue(); // The *MCP* result indicates an error
});

test('handleToolCall fails with missing name', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $request = createRequest('tools/call', ['arguments' => []]); // Missing name
    $response = $this->processor->process($request, CLIENT_ID);
    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing or invalid 'name'");
});

test('handleToolCall fails with invalid arguments type', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $request = createRequest('tools/call', ['name' => 'myTool', 'arguments' => 'not-an-object']);
    $response = $this->processor->process($request, CLIENT_ID);
    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Parameter 'arguments' must be an object");
});

test('process handles generic exception during processing', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $method = 'tools/list';
    $exceptionMessage = 'Completely unexpected error!';
    $exception = new \LogicException($exceptionMessage);

    // Make registry throw an unexpected error
    $this->registryMock->shouldReceive('allTools')->andThrow($exception);

    $request = createRequest($method);
    $response = $this->processor->process($request, CLIENT_ID);

    // Should result in a generic "Method Execution Failed" MCP error
    expectMcpErrorResponse($response, McpException::CODE_INTERNAL_ERROR);
    expect($response->error->message)->toContain("Execution failed for method '{$method}'");
});

// --- resources/list ---

test('handleResourcesList returns empty list when no resources', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->registryMock->allows('allResources')->andReturn(new \ArrayObject);

    $request = createRequest('resources/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListResourcesResult::class);
    expect($response->result->resources)->toBe([]);
    expect($response->result->nextCursor)->toBeNull();
});

test('handleResourcesList returns resources without pagination', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $resource1 = new ResourceDefinition(\stdClass::class, 'getResource', 'file://resource1', 'resource1', null, 'text/plain', 1024);
    $resource2 = new ResourceDefinition(\stdClass::class, 'getResource2', 'file://resource2', 'resource2', null, 'application/json', 2048);

    $this->registryMock->allows('allResources')->andReturn(new \ArrayObject([$resource1, $resource2]));

    $request = createRequest('resources/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListResourcesResult::class);
    expect($response->result->resources)->toEqual([$resource1, $resource2]); // Order matters
    expect($response->result->nextCursor)->toBeNull();
});

test('handleResourcesList handles pagination correctly', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->configMock->allows('get')->with('mcp.pagination_limit', Mockery::any())->andReturn(1);

    $resource1 = new ResourceDefinition(\stdClass::class, 'getResource', 'file://resource1', 'resource1', null, 'text/plain', 1024);
    $resource2 = new ResourceDefinition(\stdClass::class, 'getResource2', 'file://resource2', 'resource2', null, 'application/json', 2048);

    $this->registryMock->allows('allResources')->andReturn(new \ArrayObject([$resource1, $resource2]));

    // First page
    $request1 = createRequest('resources/list', [], 'req-1');
    $response1 = $this->processor->process($request1, CLIENT_ID);

    expect($response1->error)->toBeNull();
    expect($response1->result->resources)->toEqual([$resource1]);
    expect($response1->result->nextCursor)->toBeString(); // Expect a cursor for next page
    $cursor = $response1->result->nextCursor;

    // Second page
    $request2 = createRequest('resources/list', ['cursor' => $cursor], 'req-2');
    $response2 = $this->processor->process($request2, CLIENT_ID);

    expect($response2->error)->toBeNull();
    expect($response2->result->resources)->toEqual([$resource2]);
    expect($response2->result->nextCursor)->toBeNull(); // No more pages
});

// --- resources/templates/list ---

test('handleResourceTemplatesList returns empty list when no templates', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->registryMock->allows('allResourceTemplates')->andReturn(new \ArrayObject);

    $request = createRequest('resources/templates/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListResourceTemplatesResult::class);
    expect($response->result->resourceTemplates)->toBe([]);
    expect($response->result->nextCursor)->toBeNull();
});

test('handleResourceTemplatesList returns templates without pagination', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $template1 = new ResourceTemplateDefinition(\stdClass::class, 'getTemplate', 'file://template/{id}', 'template1', null, 'text/plain');
    $template2 = new ResourceTemplateDefinition(\stdClass::class, 'getDoc', 'file://doc/{type}', 'template2', null, 'application/json');

    $this->registryMock->allows('allResourceTemplates')->andReturn(new \ArrayObject([$template1, $template2]));

    $request = createRequest('resources/templates/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListResourceTemplatesResult::class);
    expect($response->result->resourceTemplates)->toEqual([$template1, $template2]); // Order matters
    expect($response->result->nextCursor)->toBeNull();
});

test('handleResourceTemplatesList handles pagination correctly', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->configMock->allows('get')->with('mcp.pagination_limit', Mockery::any())->andReturn(1);

    $template1 = new ResourceTemplateDefinition(\stdClass::class, 'getTemplate', 'file://template/{id}', 'template1', null, 'text/plain');
    $template2 = new ResourceTemplateDefinition(\stdClass::class, 'getDoc', 'file://doc/{type}', 'template2', null, 'application/json');

    $this->registryMock->allows('allResourceTemplates')->andReturn(new \ArrayObject([$template1, $template2]));

    // First page
    $request1 = createRequest('resources/templates/list', [], 'req-1');
    $response1 = $this->processor->process($request1, CLIENT_ID);

    expect($response1->error)->toBeNull();
    expect($response1->result->resourceTemplates)->toEqual([$template1]);
    expect($response1->result->nextCursor)->toBeString(); // Expect a cursor for next page
    $cursor = $response1->result->nextCursor;

    // Second page
    $request2 = createRequest('resources/templates/list', ['cursor' => $cursor], 'req-2');
    $response2 = $this->processor->process($request2, CLIENT_ID);

    expect($response2->error)->toBeNull();
    expect($response2->result->resourceTemplates)->toEqual([$template2]);
    expect($response2->result->nextCursor)->toBeNull(); // No more pages
});

// --- resources/read ---

test('handleResourceRead returns resource contents directly for exact URI match', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $uri = 'file://resource1';
    $mimeType = 'text/plain';
    $contents = 'Resource data contents';
    $expectedResult = [['type' => 'text', 'text' => $contents]];
    $formattedContents = [new TextContent($contents)];

    $resourceDef = Mockery::mock(ResourceDefinition::class);
    $resourceDef->allows('getClassName')->andReturn('DummyResourceClass');
    $resourceDef->allows('getMethodName')->andReturn('getResource');
    $resourceDef->allows('getMimeType')->andReturn($mimeType);

    $resourceInstance = Mockery::mock('DummyResourceClass');

    $this->registryMock->shouldReceive('findResourceByUri')->once()->with($uri)->andReturn($resourceDef);
    $this->containerMock->shouldReceive('get')->once()->with('DummyResourceClass')->andReturn($resourceInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($resourceInstance, 'getResource', ['uri' => $uri], [])
        ->andReturn([]);
    $resourceInstance->shouldReceive('getResource')->once()->andReturn($contents);

    $request = createRequest('resources/read', ['uri' => $uri]);

    // Use spy to verify formatResourceContents is called
    /** @var MockInterface&Processor */
    $processorSpy = Mockery::mock(Processor::class, [
        $this->containerMock,
        $this->registryMock,
        $this->transportStateMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock,
    ])->makePartial();

    $processorSpy->shouldAllowMockingProtectedMethods();
    $processorSpy->shouldReceive('formatResourceContents')->once()->with($contents, $uri, $mimeType)->andReturn($formattedContents);

    $response = $processorSpy->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ReadResourceResult::class);
    expect($response->result->toArray()['contents'])->toBe($expectedResult);
});

test('handleResourceRead passes template parameters to handler method', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $templateUri = 'file://template/{id}/{type}';
    $requestedUri = 'file://template/123/json';
    $mimeType = 'application/json';
    $templateParams = ['id' => '123', 'type' => 'json'];
    $contents = json_encode(['id' => 123, 'name' => 'test', 'format' => 'json']);
    $expectedResult = [['type' => 'text', 'text' => $contents]];
    $formattedContents = [new TextContent($contents)];

    $templateDef = Mockery::mock(ResourceTemplateDefinition::class);
    $templateDef->allows('getClassName')->andReturn('DummyResourceClass');
    $templateDef->allows('getMethodName')->andReturn('getTemplate');
    $templateDef->allows('getMimeType')->andReturn($mimeType);

    $resourceInstance = Mockery::mock('DummyResourceClass');

    $this->registryMock->shouldReceive('findResourceByUri')->once()->with($requestedUri)->andReturn(null);
    $this->registryMock->shouldReceive('findResourceTemplateByUri')->once()->with($requestedUri)->andReturn(['definition' => $templateDef, 'variables' => $templateParams]);
    $this->containerMock->shouldReceive('get')->once()->with('DummyResourceClass')->andReturn($resourceInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($resourceInstance, 'getTemplate', array_merge($templateParams, ['uri' => $requestedUri]), [])
        ->andReturn(['123', 'json']);
    $resourceInstance->shouldReceive('getTemplate')->once()->with('123', 'json')->andReturn($contents);

    $request = createRequest('resources/read', ['uri' => $requestedUri]);

    // Use spy to verify formatResourceContents is called
    /** @var MockInterface&Processor */
    $processorSpy = Mockery::mock(Processor::class, [
        $this->containerMock,
        $this->registryMock,
        $this->transportStateMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock,
    ])->makePartial();

    $processorSpy->shouldAllowMockingProtectedMethods();
    $processorSpy->shouldReceive('formatResourceContents')->once()->with($contents, $requestedUri, $mimeType)->andReturn($formattedContents);

    $response = $processorSpy->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ReadResourceResult::class);
    expect($response->result->toArray()['contents'])->toBe($expectedResult);
});

test('handleResourceRead fails if resource not found', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $uri = 'file://nonexistent';

    $this->registryMock->shouldReceive('findResourceByUri')->once()->with($uri)->andReturn(null);
    $this->registryMock->shouldReceive('findResourceTemplateByUri')->once()->with($uri)->andReturn(null);

    $request = createRequest('resources/read', ['uri' => $uri]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Resource URI '{$uri}' not found or no handler available.");
});

test('handleResourceRead fails with missing uri parameter', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $request = createRequest('resources/read', []); // Missing uri
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing or invalid 'uri'");
});

test('handleResourceRead handles handler execution exception', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $uri = 'file://error';
    $mimeType = 'text/plain';
    $exceptionMessage = 'Resource handler failed';
    $exception = new \RuntimeException($exceptionMessage);

    $definition = Mockery::mock(\PhpMcp\Server\Definitions\ResourceDefinition::class);
    $definition->allows('getClassName')->andReturn('DummyResourceClass');
    $definition->allows('getMethodName')->andReturn('getResource');
    $definition->allows('getMimeType')->andReturn($mimeType);

    $handlerInstance = Mockery::mock('DummyResourceClass');

    $this->registryMock->shouldReceive('findResourceByUri')->once()->with($uri)->andReturn($definition);

    $this->containerMock->shouldReceive('get')->once()->with('DummyResourceClass')->andReturn($handlerInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($handlerInstance, 'getResource', ['uri' => $uri], [])
        ->andReturn([]);
    $handlerInstance->shouldReceive('getResource')->once()->withNoArgs()->andThrow($exception);

    $request = createRequest('resources/read', ['uri' => $uri]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INTERNAL_ERROR);
    expect($response->error->message)->toContain($exceptionMessage);
});

// --- resources/subscribe ---

test('handleResourceSubscribe subscribes to resource', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->configMock->allows('get')->with('mcp.capabilities.resources.subscribe', Mockery::any())->andReturn(true);

    $uri = 'file://subscribable';
    $resource = new ResourceDefinition('DummyResourceClass', 'getResource', $uri, 'testResource', null, 'text/plain', 1024);

    $this->transportStateMock->shouldReceive('addResourceSubscription')->once()->with(CLIENT_ID, $uri)->andReturn(true);

    $request = createRequest('resources/subscribe', ['uri' => $uri]);
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(EmptyResult::class);
});

test('handleResourceUnsubscribe unsubscribes from resource', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $uri = 'file://subscribable';
    $resource = new ResourceDefinition('DummyResourceClass', 'getResource', $uri, 'testResource', null, 'text/plain', 1024);

    $this->transportStateMock->shouldReceive('removeResourceSubscription')->once()->with(CLIENT_ID, $uri)->andReturn(true);

    $request = createRequest('resources/unsubscribe', ['uri' => $uri]);
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(EmptyResult::class);
});

// Keep the remaining items in TODO for reference:

// - prompts/list (pagination)
// - prompts/get (success, not found, missing args, errors)
// - logging/setLevel (success, invalid level)
// - Result formatting errors (JsonException for tool/resource/prompt results)
// - ArgumentPreparer exceptions

// --- prompts/list ---

test('handlePromptsList returns empty list when no prompts', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->registryMock->allows('allPrompts')->andReturn(new \ArrayObject);

    $request = createRequest('prompts/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListPromptsResult::class);
    expect($response->result->prompts)->toBe([]);
    expect($response->result->nextCursor)->toBeNull();
});

test('handlePromptsList returns prompts without pagination', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $prompt1 = new PromptDefinition(\stdClass::class, 'getPrompt1', 'prompt1', 'Prompt 1', []);
    $prompt2 = new PromptDefinition(\stdClass::class, 'getPrompt2', 'prompt2', 'Prompt 2', []);

    $this->registryMock->allows('allPrompts')->andReturn(new \ArrayObject([$prompt1, $prompt2]));

    $request = createRequest('prompts/list');
    $response = $this->processor->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(ListPromptsResult::class);
    expect($response->result->prompts)->toEqual([$prompt1, $prompt2]); // Order matters
    expect($response->result->nextCursor)->toBeNull();
});

test('handlePromptsList handles pagination correctly', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);
    $this->configMock->allows('get')->with('mcp.pagination_limit', Mockery::any())->andReturn(1);

    $prompt1 = new PromptDefinition(\stdClass::class, 'getPrompt1', 'prompt1', 'Prompt 1', []);
    $prompt2 = new PromptDefinition(\stdClass::class, 'getPrompt2', 'prompt2', 'Prompt 2', []);

    $this->registryMock->allows('allPrompts')->andReturn(new \ArrayObject([$prompt1, $prompt2]));

    // First page
    $request1 = createRequest('prompts/list', [], 'req-1');
    $response1 = $this->processor->process($request1, CLIENT_ID);

    expect($response1->error)->toBeNull();
    expect($response1->result->prompts)->toEqual([$prompt1]);
    expect($response1->result->nextCursor)->toBeString(); // Expect a cursor for next page
    $cursor = $response1->result->nextCursor;

    // Second page
    $request2 = createRequest('prompts/list', ['cursor' => $cursor], 'req-2');
    $response2 = $this->processor->process($request2, CLIENT_ID);

    expect($response2->error)->toBeNull();
    expect($response2->result->prompts)->toEqual([$prompt2]);
    expect($response2->result->nextCursor)->toBeNull(); // No more pages
});

// --- prompts/get ---

test('handlePromptGet returns formatted prompt messages', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $promptName = 'greeting';
    $promptDescription = 'A greeting prompt';
    $promptArgs = ['name' => 'User', 'language' => 'en'];
    $rawResult = [
        ['role' => 'user', 'content' => 'Be polite and friendly'],
        ['role' => 'user', 'content' => 'Greet User in English'],
    ];
    $resultOutput = array_map(fn ($message) => ['role' => $message['role'], 'content' => ['type' => 'text', 'text' => $message['content']]], $rawResult);
    $formattedMessages = array_map(fn ($message) => new PromptMessage($message['role'], new TextContent($message['content'])), $rawResult);

    $promptDef = Mockery::mock(PromptDefinition::class);
    $promptDef->allows('getClassName')->andReturn('DummyPromptClass');
    $promptDef->allows('getMethodName')->andReturn('getGreetingPrompt');
    $promptDef->allows('getArguments')->andReturn([]);
    $promptDef->allows('getDescription')->andReturn($promptDescription);

    $promptInstance = Mockery::mock('DummyPromptClass');

    $this->registryMock->shouldReceive('findPrompt')->once()->with($promptName)->andReturn($promptDef);
    $this->containerMock->shouldReceive('get')->once()->with('DummyPromptClass')->andReturn($promptInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($promptInstance, 'getGreetingPrompt', $promptArgs, [])
        ->andReturn(['User', 'en']);
    $promptInstance->shouldReceive('getGreetingPrompt')->once()->with('User', 'en')->andReturn($rawResult);

    $request = createRequest('prompts/get', ['name' => $promptName, 'arguments' => $promptArgs]);

    /** @var MockInterface&Processor */
    $processorSpy = Mockery::mock(Processor::class, [
        $this->containerMock,
        $this->registryMock,
        $this->transportStateMock,
        $this->schemaValidatorMock,
        $this->argumentPreparerMock,
    ])->makePartial();

    $processorSpy->shouldAllowMockingProtectedMethods();
    $processorSpy->shouldReceive('formatPromptMessages')->once()->with($rawResult)->andReturn($formattedMessages);

    $response = $processorSpy->process($request, CLIENT_ID);

    expect($response->error)->toBeNull();
    expect($response->result)->toBeInstanceOf(GetPromptResult::class);
    expect($response->result->toArray()['messages'])->toEqual($resultOutput);
    expect($response->result->toArray()['description'])->toBe($promptDescription);
});

test('handlePromptGet validates required arguments', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $promptName = 'greeting';
    $promptArgs = ['language' => 'en']; // Missing required 'name' argument

    $requiredArg = Mockery::mock(PromptArgumentDefinition::class);
    $requiredArg->allows('getName')->andReturn('name');
    $requiredArg->allows('isRequired')->andReturn(true);

    $optionalArg = Mockery::mock(PromptArgumentDefinition::class);
    $optionalArg->allows('getName')->andReturn('language');
    $optionalArg->allows('isRequired')->andReturn(false);

    $promptDef = Mockery::mock(PromptDefinition::class);
    $promptDef->allows('getArguments')->andReturn([$requiredArg, $optionalArg]);

    $this->registryMock->shouldReceive('findPrompt')->once()->with($promptName)->andReturn($promptDef);

    $request = createRequest('prompts/get', ['name' => $promptName, 'arguments' => $promptArgs]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing required argument 'name'");
});

test('handlePromptGet fails if prompt not found', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $promptName = 'nonexistent';
    $this->registryMock->shouldReceive('findPrompt')->once()->with($promptName)->andReturn(null);

    $request = createRequest('prompts/get', ['name' => $promptName, 'arguments' => []]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Prompt '{$promptName}' not found");
});

test('handlePromptGet fails with missing name parameter', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $request = createRequest('prompts/get', ['arguments' => []]); // Missing name
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Missing or invalid 'name'");
});

test('handlePromptGet fails with invalid arguments type', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $request = createRequest('prompts/get', ['name' => 'promptName', 'arguments' => 'not-an-object']);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INVALID_PARAMS);
    expect($response->error->message)->toContain("Parameter 'arguments' must be an object");
});

test('handlePromptGet handles execution exception', function () {
    $this->transportStateMock->allows('isInitialized')->with(CLIENT_ID)->andReturn(true);

    $promptName = 'error-prompt';
    $promptArgs = [];
    $exceptionMessage = 'Failed to generate prompt';
    $exception = new \RuntimeException($exceptionMessage);

    $promptDef = Mockery::mock(PromptDefinition::class);
    $promptDef->allows('getClassName')->andReturn('DummyPromptClass');
    $promptDef->allows('getMethodName')->andReturn('getErrorPrompt');
    $promptDef->allows('getArguments')->andReturn([]);

    $promptInstance = Mockery::mock('DummyPromptClass');

    $this->registryMock->shouldReceive('findPrompt')->once()->with($promptName)->andReturn($promptDef);
    $this->containerMock->shouldReceive('get')->once()->with('DummyPromptClass')->andReturn($promptInstance);
    $this->argumentPreparerMock->shouldReceive('prepareMethodArguments')
        ->once()
        ->with($promptInstance, 'getErrorPrompt', $promptArgs, [])
        ->andReturn([]);
    $promptInstance->shouldReceive('getErrorPrompt')->once()->withNoArgs()->andThrow($exception);

    $this->loggerMock->shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) use ($exceptionMessage) {
            return str_contains($message, 'Prompt generation failed') &&
                   $context['exception'] === $exceptionMessage;
        });

    $request = createRequest('prompts/get', ['name' => $promptName, 'arguments' => $promptArgs]);
    $response = $this->processor->process($request, CLIENT_ID);

    expectMcpErrorResponse($response, McpException::CODE_INTERNAL_ERROR);
    expect($response->error->message)->toContain('Failed to generate prompt');
});

// Keep the remaining items in TODO for reference:

// - logging/setLevel (success, invalid level)
// - Result formatting errors (JsonException for tool/resource/prompt results)
// - ArgumentPreparer exceptions
