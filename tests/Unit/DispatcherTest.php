<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Schema\ClientCapabilities;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Elements\RegisteredPrompt;
use PhpMcp\Server\Elements\RegisteredResource;
use PhpMcp\Server\Elements\RegisteredResourceTemplate;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\JsonRpc\Notification as JsonRpcNotification;
use PhpMcp\Schema\JsonRpc\Request as JsonRpcRequest;
use PhpMcp\Schema\Prompt as PromptSchema;
use PhpMcp\Schema\PromptArgument;
use PhpMcp\Schema\Request\CallToolRequest;
use PhpMcp\Schema\Request\CompletionCompleteRequest;
use PhpMcp\Schema\Request\GetPromptRequest;
use PhpMcp\Schema\Request\InitializeRequest;
use PhpMcp\Schema\Request\ListToolsRequest;
use PhpMcp\Schema\Request\ReadResourceRequest;
use PhpMcp\Schema\Request\ResourceSubscribeRequest;
use PhpMcp\Schema\Request\SetLogLevelRequest;
use PhpMcp\Schema\Resource as ResourceSchema;
use PhpMcp\Schema\ResourceTemplate as ResourceTemplateSchema;
use PhpMcp\Schema\Result\CallToolResult;
use PhpMcp\Schema\Result\CompletionCompleteResult;
use PhpMcp\Schema\Result\EmptyResult;
use PhpMcp\Schema\Result\GetPromptResult;
use PhpMcp\Schema\Result\InitializeResult;
use PhpMcp\Schema\Result\ReadResourceResult;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Schema\Tool as ToolSchema;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SubscriptionManager;
use PhpMcp\Server\Utils\SchemaValidator;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\PromptMessage;
use PhpMcp\Schema\Enum\LoggingLevel;
use PhpMcp\Schema\Enum\Role;
use PhpMcp\Schema\PromptReference;
use PhpMcp\Schema\Request\ListPromptsRequest;
use PhpMcp\Schema\Request\ListResourcesRequest;
use PhpMcp\Schema\Request\ListResourceTemplatesRequest;
use PhpMcp\Schema\ResourceReference;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Tests\Fixtures\Enums\StatusEnum;
use React\EventLoop\Loop;

const DISPATCHER_SESSION_ID = 'dispatcher-session-xyz';
const DISPATCHER_PAGINATION_LIMIT = 3;

beforeEach(function () {
    /** @var MockInterface&Configuration $configuration */
    $this->configuration = Mockery::mock(Configuration::class);
    /** @var MockInterface&Registry $registry */
    $this->registry = Mockery::mock(Registry::class);
    /** @var MockInterface&SubscriptionManager $subscriptionManager */
    $this->subscriptionManager = Mockery::mock(SubscriptionManager::class);
    /** @var MockInterface&SchemaValidator $schemaValidator */
    $this->schemaValidator = Mockery::mock(SchemaValidator::class);
    /** @var MockInterface&SessionInterface $session */
    $this->session = Mockery::mock(SessionInterface::class);
    /** @var MockInterface&ContainerInterface $container */
    $this->container = Mockery::mock(ContainerInterface::class);

    $configuration = new Configuration(
        serverInfo: Implementation::make('DispatcherTestServer', '1.0'),
        capabilities: ServerCapabilities::make(),
        paginationLimit: DISPATCHER_PAGINATION_LIMIT,
        logger: new NullLogger(),
        loop: Loop::get(),
        cache: null,
        container: $this->container
    );

    $this->dispatcher = new Dispatcher(
        $configuration,
        $this->registry,
        $this->subscriptionManager,
        $this->schemaValidator
    );
});

it('routes to handleInitialize for initialize request', function () {
    $request = new JsonRpcRequest(
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: [
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'client', 'version' => '1.0'],
            'capabilities' => [],
        ]
    );
    $this->session->shouldReceive('set')->with('client_info', Mockery::on(fn($value) => $value['name'] === 'client' && $value['version'] === '1.0'))->once();
    $this->session->shouldReceive('set')->with('protocol_version', Protocol::LATEST_PROTOCOL_VERSION)->once();

    $result = $this->dispatcher->handleRequest($request, $this->session);
    expect($result)->toBeInstanceOf(InitializeResult::class);
    expect($result->protocolVersion)->toBe(Protocol::LATEST_PROTOCOL_VERSION);
    expect($result->serverInfo->name)->toBe('DispatcherTestServer');
});

it('routes to handlePing for ping request', function () {
    $request = new JsonRpcRequest('2.0', 'id1', 'ping', []);
    $result = $this->dispatcher->handleRequest($request, $this->session);
    expect($result)->toBeInstanceOf(EmptyResult::class);
});

it('throws MethodNotFound for unknown request method', function () {
    $rawRequest = new JsonRpcRequest('2.0', 'id1', 'unknown/method', []);
    $this->dispatcher->handleRequest($rawRequest, $this->session);
})->throws(McpServerException::class, "Method 'unknown/method' not found.");

it('routes to handleNotificationInitialized for initialized notification', function () {
    $notification = new JsonRpcNotification('2.0', 'notifications/initialized', []);
    $this->session->shouldReceive('set')->with('initialized', true)->once();
    $this->dispatcher->handleNotification($notification, $this->session);
});

it('does nothing for unknown notification method', function () {
    $rawNotification = new JsonRpcNotification('2.0', 'unknown/notification', []);
    $this->session->shouldNotReceive('set');
    $this->dispatcher->handleNotification($rawNotification, $this->session);
});


it('can handle initialize request', function () {
    $clientInfo = Implementation::make('TestClient', '0.9.9');
    $request = InitializeRequest::make(1, Protocol::LATEST_PROTOCOL_VERSION, ClientCapabilities::make(), $clientInfo, []);
    $this->session->shouldReceive('set')->with('client_info', $clientInfo->toArray())->once();
    $this->session->shouldReceive('set')->with('protocol_version', Protocol::LATEST_PROTOCOL_VERSION)->once();

    $result = $this->dispatcher->handleInitialize($request, $this->session);
    expect($result->protocolVersion)->toBe(Protocol::LATEST_PROTOCOL_VERSION);
    expect($result->serverInfo->name)->toBe('DispatcherTestServer');
    expect($result->capabilities)->toBeInstanceOf(ServerCapabilities::class);
});

it('can handle initialize request with older supported protocol version', function () {
    $clientInfo = Implementation::make('TestClient', '0.9.9');
    $clientRequestedVersion = '2024-11-05';
    $request = InitializeRequest::make(1, $clientRequestedVersion, ClientCapabilities::make(), $clientInfo, []);
    $this->session->shouldReceive('set')->with('client_info', $clientInfo->toArray())->once();
    $this->session->shouldReceive('set')->with('protocol_version', $clientRequestedVersion)->once();

    $result = $this->dispatcher->handleInitialize($request, $this->session);
    expect($result->protocolVersion)->toBe($clientRequestedVersion);
    expect($result->serverInfo->name)->toBe('DispatcherTestServer');
    expect($result->capabilities)->toBeInstanceOf(ServerCapabilities::class);
});

it('can handle initialize request with unsupported protocol version', function () {
    $clientInfo = Implementation::make('TestClient', '0.9.9');
    $unsupportedVersion = '1999-01-01';
    $request = InitializeRequest::make(1, $unsupportedVersion, ClientCapabilities::make(), $clientInfo, []);
    $this->session->shouldReceive('set')->with('client_info', $clientInfo->toArray())->once();
    $this->session->shouldReceive('set')->with('protocol_version', Protocol::LATEST_PROTOCOL_VERSION)->once();

    $result = $this->dispatcher->handleInitialize($request, $this->session);
    expect($result->protocolVersion)->toBe(Protocol::LATEST_PROTOCOL_VERSION);
    expect($result->serverInfo->name)->toBe('DispatcherTestServer');
    expect($result->capabilities)->toBeInstanceOf(ServerCapabilities::class);
});

it('can handle tool list request and return paginated tools', function () {
    $toolSchemas = [
        ToolSchema::make('tool1', ['type' => 'object', 'properties' => []]),
        ToolSchema::make('tool2', ['type' => 'object', 'properties' => []]),
        ToolSchema::make('tool3', ['type' => 'object', 'properties' => []]),
        ToolSchema::make('tool4', ['type' => 'object', 'properties' => []]),
    ];
    $this->registry->shouldReceive('getTools')->andReturn($toolSchemas);

    $request = ListToolsRequest::make(1);
    $result = $this->dispatcher->handleToolList($request);
    expect($result->tools)->toHaveCount(DISPATCHER_PAGINATION_LIMIT);
    expect($result->tools[0]->name)->toBe('tool1');
    expect($result->nextCursor)->toBeString();

    $nextCursor = $result->nextCursor;
    $requestPage2 = ListToolsRequest::make(2, $nextCursor);
    $resultPage2 = $this->dispatcher->handleToolList($requestPage2);
    expect($resultPage2->tools)->toHaveCount(count($toolSchemas) - DISPATCHER_PAGINATION_LIMIT);
    expect($resultPage2->tools[0]->name)->toBe('tool4');
    expect($resultPage2->nextCursor)->toBeNull();
});

it('can handle tool call request and return result', function () {
    $toolName = 'my-calculator';
    $args = ['a' => 10, 'b' => 5];
    $toolSchema = ToolSchema::make($toolName, ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]]);
    $registeredToolMock = Mockery::mock(RegisteredTool::class, [$toolSchema, 'MyToolHandler', 'handleTool', false]);

    $this->registry->shouldReceive('getTool')->with($toolName)->andReturn($registeredToolMock);
    $this->schemaValidator->shouldReceive('validateAgainstJsonSchema')->with($args, $toolSchema->inputSchema)->andReturn([]); // No validation errors
    $registeredToolMock->shouldReceive('call')->with($this->container, $args)->andReturn([TextContent::make("Result: 15")]);

    $request = CallToolRequest::make(1, $toolName, $args);
    $result = $this->dispatcher->handleToolCall($request);

    expect($result)->toBeInstanceOf(CallToolResult::class);
    expect($result->content[0]->text)->toBe("Result: 15");
    expect($result->isError)->toBeFalse();
});

it('can handle tool call request and throw exception if tool not found', function () {
    $this->registry->shouldReceive('getTool')->with('unknown-tool')->andReturn(null);
    $request = CallToolRequest::make(1, 'unknown-tool', []);
    $this->dispatcher->handleToolCall($request);
})->throws(McpServerException::class, "Tool 'unknown-tool' not found.");

it('can handle tool call request and throw exception if argument validation fails', function () {
    $toolName = 'strict-tool';
    $args = ['param' => 'wrong_type'];
    $toolSchema = ToolSchema::make($toolName, ['type' => 'object', 'properties' => ['param' => ['type' => 'integer']]]);
    $registeredToolMock = Mockery::mock(RegisteredTool::class, [$toolSchema, 'MyToolHandler', 'handleTool', false]);

    $this->registry->shouldReceive('getTool')->with($toolName)->andReturn($registeredToolMock);
    $validationErrors = [['pointer' => '/param', 'keyword' => 'type', 'message' => 'Expected integer']];
    $this->schemaValidator->shouldReceive('validateAgainstJsonSchema')->with($args, $toolSchema->inputSchema)->andReturn($validationErrors);

    $request = CallToolRequest::make(1, $toolName, $args);
    try {
        $this->dispatcher->handleToolCall($request);
    } catch (McpServerException $e) {
        expect($e->getMessage())->toContain("Invalid parameters for tool 'strict-tool'");
        expect($e->getData()['validation_errors'])->toBeArray();
    }
});

it('can handle tool call request and return error if tool execution throws exception', function () {
    $toolName = 'failing-tool';
    $toolSchema = ToolSchema::make($toolName, ['type' => 'object', 'properties' => []]);
    $registeredToolMock = Mockery::mock(RegisteredTool::class, [$toolSchema, 'MyToolHandler', 'handleTool', false]);

    $this->registry->shouldReceive('getTool')->with($toolName)->andReturn($registeredToolMock);
    $this->schemaValidator->shouldReceive('validateAgainstJsonSchema')->andReturn([]);
    $registeredToolMock->shouldReceive('call')->andThrow(new \RuntimeException("Tool crashed!"));

    $request = CallToolRequest::make(1, $toolName, []);
    $result = $this->dispatcher->handleToolCall($request);

    expect($result->isError)->toBeTrue();
    expect($result->content[0]->text)->toBe("Tool execution failed: Tool crashed!");
});

it('can handle tool call request and return error if result formatting fails', function () {
    $toolName = 'bad-result-tool';
    $toolSchema = ToolSchema::make($toolName, ['type' => 'object', 'properties' => []]);
    $registeredToolMock = Mockery::mock(RegisteredTool::class, [$toolSchema, 'MyToolHandler', 'handleTool', false]);

    $this->registry->shouldReceive('getTool')->with($toolName)->andReturn($registeredToolMock);
    $this->schemaValidator->shouldReceive('validateAgainstJsonSchema')->andReturn([]);
    $registeredToolMock->shouldReceive('call')->andThrow(new \JsonException("Unencodable."));


    $request = CallToolRequest::make(1, $toolName, []);
    $result = $this->dispatcher->handleToolCall($request);

    expect($result->isError)->toBeTrue();
    expect($result->content[0]->text)->toBe("Failed to serialize tool result: Unencodable.");
});


it('can handle resources list request and return paginated resources', function () {
    $resourceSchemas = [
        ResourceSchema::make('res://1', 'Resource1'),
        ResourceSchema::make('res://2', 'Resource2'),
        ResourceSchema::make('res://3', 'Resource3'),
        ResourceSchema::make('res://4', 'Resource4'),
        ResourceSchema::make('res://5', 'Resource5')
    ];
    $this->registry->shouldReceive('getResources')->andReturn($resourceSchemas);

    $requestP1 = ListResourcesRequest::make(1);
    $resultP1 = $this->dispatcher->handleResourcesList($requestP1);
    expect($resultP1->resources)->toHaveCount(DISPATCHER_PAGINATION_LIMIT);
    expect(array_map(fn($r) => $r->name, $resultP1->resources))->toEqual(['Resource1', 'Resource2', 'Resource3']);
    expect($resultP1->nextCursor)->toBe(base64_encode('offset=3'));

    // Page 2
    $requestP2 = ListResourcesRequest::make(2, $resultP1->nextCursor);
    $resultP2 = $this->dispatcher->handleResourcesList($requestP2);
    expect($resultP2->resources)->toHaveCount(2);
    expect(array_map(fn($r) => $r->name, $resultP2->resources))->toEqual(['Resource4', 'Resource5']);
    expect($resultP2->nextCursor)->toBeNull();
});

it('can handle resources list request and return empty if registry has no resources', function () {
    $this->registry->shouldReceive('getResources')->andReturn([]);
    $request = ListResourcesRequest::make(1);
    $result = $this->dispatcher->handleResourcesList($request);
    expect($result->resources)->toBeEmpty();
    expect($result->nextCursor)->toBeNull();
});

it('can handle resource template list request and return paginated templates', function () {
    $templateSchemas = [
        ResourceTemplateSchema::make('tpl://{id}/1', 'Template1'),
        ResourceTemplateSchema::make('tpl://{id}/2', 'Template2'),
        ResourceTemplateSchema::make('tpl://{id}/3', 'Template3'),
        ResourceTemplateSchema::make('tpl://{id}/4', 'Template4'),
    ];
    $this->registry->shouldReceive('getResourceTemplates')->andReturn($templateSchemas);

    // Page 1
    $requestP1 = ListResourceTemplatesRequest::make(1);
    $resultP1 = $this->dispatcher->handleResourceTemplateList($requestP1);
    expect($resultP1->resourceTemplates)->toHaveCount(DISPATCHER_PAGINATION_LIMIT);
    expect(array_map(fn($rt) => $rt->name, $resultP1->resourceTemplates))->toEqual(['Template1', 'Template2', 'Template3']);
    expect($resultP1->nextCursor)->toBe(base64_encode('offset=3'));

    // Page 2
    $requestP2 = ListResourceTemplatesRequest::make(2, $resultP1->nextCursor);
    $resultP2 = $this->dispatcher->handleResourceTemplateList($requestP2);
    expect($resultP2->resourceTemplates)->toHaveCount(1);
    expect(array_map(fn($rt) => $rt->name, $resultP2->resourceTemplates))->toEqual(['Template4']);
    expect($resultP2->nextCursor)->toBeNull();
});

it('can handle resource read request and return resource contents', function () {
    $uri = 'file://data.txt';
    $resourceSchema = ResourceSchema::make($uri, 'file_resource');
    $registeredResourceMock = Mockery::mock(RegisteredResource::class, [$resourceSchema, ['MyResourceHandler', 'read'], false]);
    $resourceContents = [TextContent::make('File content')];

    $this->registry->shouldReceive('getResource')->with($uri)->andReturn($registeredResourceMock);
    $registeredResourceMock->shouldReceive('read')->with($this->container, $uri)->andReturn($resourceContents);

    $request = ReadResourceRequest::make(1, $uri);
    $result = $this->dispatcher->handleResourceRead($request);

    expect($result)->toBeInstanceOf(ReadResourceResult::class);
    expect($result->contents)->toEqual($resourceContents);
});

it('can handle resource read request and throw exception if resource not found', function () {
    $this->registry->shouldReceive('getResource')->with('unknown://uri')->andReturn(null);
    $request = ReadResourceRequest::make(1, 'unknown://uri');
    $this->dispatcher->handleResourceRead($request);
})->throws(McpServerException::class, "Resource URI 'unknown://uri' not found.");

it('can handle resource subscribe request and call subscription manager', function () {
    $uri = 'news://updates';
    $this->session->shouldReceive('getId')->andReturn(DISPATCHER_SESSION_ID);
    $this->subscriptionManager->shouldReceive('subscribe')->with(DISPATCHER_SESSION_ID, $uri)->once();
    $request = ResourceSubscribeRequest::make(1, $uri);
    $result = $this->dispatcher->handleResourceSubscribe($request, $this->session);
    expect($result)->toBeInstanceOf(EmptyResult::class);
});

it('can handle prompts list request and return paginated prompts', function () {
    $promptSchemas = [
        PromptSchema::make('promptA', '', []),
        PromptSchema::make('promptB', '', []),
        PromptSchema::make('promptC', '', []),
        PromptSchema::make('promptD', '', []),
        PromptSchema::make('promptE', '', []),
        PromptSchema::make('promptF', '', []),
    ]; // 6 prompts
    $this->registry->shouldReceive('getPrompts')->andReturn($promptSchemas);

    // Page 1
    $requestP1 = ListPromptsRequest::make(1);
    $resultP1 = $this->dispatcher->handlePromptsList($requestP1);
    expect($resultP1->prompts)->toHaveCount(DISPATCHER_PAGINATION_LIMIT);
    expect(array_map(fn($p) => $p->name, $resultP1->prompts))->toEqual(['promptA', 'promptB', 'promptC']);
    expect($resultP1->nextCursor)->toBe(base64_encode('offset=3'));

    // Page 2
    $requestP2 = ListPromptsRequest::make(2, $resultP1->nextCursor);
    $resultP2 = $this->dispatcher->handlePromptsList($requestP2);
    expect($resultP2->prompts)->toHaveCount(DISPATCHER_PAGINATION_LIMIT); // 3 more
    expect(array_map(fn($p) => $p->name, $resultP2->prompts))->toEqual(['promptD', 'promptE', 'promptF']);
    expect($resultP2->nextCursor)->toBeNull(); // End of list
});

it('can handle prompt get request and return prompt messages', function () {
    $promptName = 'daily-summary';
    $args = ['date' => '2024-07-16'];
    $promptSchema = PromptSchema::make($promptName, 'summary_prompt', [PromptArgument::make('date', required: true)]);
    $registeredPromptMock = Mockery::mock(RegisteredPrompt::class, [$promptSchema, ['MyPromptHandler', 'get'], false]);
    $promptMessages = [PromptMessage::make(Role::User, TextContent::make("Summary for 2024-07-16"))];

    $this->registry->shouldReceive('getPrompt')->with($promptName)->andReturn($registeredPromptMock);
    $registeredPromptMock->shouldReceive('get')->with($this->container, $args)->andReturn($promptMessages);

    $request = GetPromptRequest::make(1, $promptName, $args);
    $result = $this->dispatcher->handlePromptGet($request, $this->session);

    expect($result)->toBeInstanceOf(GetPromptResult::class);
    expect($result->messages)->toEqual($promptMessages);
    expect($result->description)->toBe($promptSchema->description);
});

it('can handle prompt get request and throw exception if required argument is missing', function () {
    $promptName = 'needs-topic';
    $promptSchema = PromptSchema::make($promptName, '', [PromptArgument::make('topic', required: true)]);
    $registeredPromptMock = Mockery::mock(RegisteredPrompt::class, [$promptSchema, ['MyPromptHandler', 'get'], false]);
    $this->registry->shouldReceive('getPrompt')->with($promptName)->andReturn($registeredPromptMock);

    $request = GetPromptRequest::make(1, $promptName, ['other_arg' => 'value']); // 'topic' is missing
    $this->dispatcher->handlePromptGet($request, $this->session);
})->throws(McpServerException::class, "Missing required argument 'topic' for prompt 'needs-topic'.");


it('can handle logging set level request and set log level on session', function () {
    $level = LoggingLevel::Debug;
    $this->session->shouldReceive('getId')->andReturn(DISPATCHER_SESSION_ID);
    $this->session->shouldReceive('set')->with('log_level', 'debug')->once();

    $request = SetLogLevelRequest::make(1, $level);
    $result = $this->dispatcher->handleLoggingSetLevel($request, $this->session);

    expect($result)->toBeInstanceOf(EmptyResult::class);
});

it('can handle completion complete request for prompt and delegate to provider', function () {
    $promptName = 'my-completable-prompt';
    $argName = 'tagName';
    $currentValue = 'php';
    $completions = ['php-mcp', 'php-fig'];
    $mockCompletionProvider = Mockery::mock(CompletionProviderInterface::class);
    $providerClass = get_class($mockCompletionProvider);

    $promptSchema = PromptSchema::make($promptName, '', [PromptArgument::make($argName)]);
    $registeredPrompt = new RegisteredPrompt(
        schema: $promptSchema,
        handler: ['MyPromptHandler', 'get'],
        isManual: false,
        completionProviders: [$argName => $providerClass]
    );

    $this->registry->shouldReceive('getPrompt')->with($promptName)->andReturn($registeredPrompt);
    $this->container->shouldReceive('get')->with($providerClass)->andReturn($mockCompletionProvider);
    $mockCompletionProvider->shouldReceive('getCompletions')->with($currentValue, $this->session)->andReturn($completions);

    $request = CompletionCompleteRequest::make(1, PromptReference::make($promptName), ['name' => $argName, 'value' => $currentValue]);
    $result = $this->dispatcher->handleCompletionComplete($request, $this->session);

    expect($result)->toBeInstanceOf(CompletionCompleteResult::class);
    expect($result->values)->toEqual($completions);
    expect($result->total)->toBe(count($completions));
    expect($result->hasMore)->toBeFalse();
});

it('can handle completion complete request for resource template and delegate to provider', function () {
    $templateUri = 'item://{itemId}/category/{catName}';
    $uriVarName = 'catName';
    $currentValue = 'boo';
    $completions = ['books', 'boomerangs'];
    $mockCompletionProvider = Mockery::mock(CompletionProviderInterface::class);
    $providerClass = get_class($mockCompletionProvider);

    $templateSchema = ResourceTemplateSchema::make($templateUri, 'item-template');
    $registeredTemplate = new RegisteredResourceTemplate(
        schema: $templateSchema,
        handler: ['MyResourceTemplateHandler', 'get'],
        isManual: false,
        completionProviders: [$uriVarName => $providerClass]
    );

    $this->registry->shouldReceive('getResourceTemplate')->with($templateUri)->andReturn($registeredTemplate);
    $this->container->shouldReceive('get')->with($providerClass)->andReturn($mockCompletionProvider);
    $mockCompletionProvider->shouldReceive('getCompletions')->with($currentValue, $this->session)->andReturn($completions);

    $request = CompletionCompleteRequest::make(1, ResourceReference::make($templateUri), ['name' => $uriVarName, 'value' => $currentValue]);
    $result = $this->dispatcher->handleCompletionComplete($request, $this->session);

    expect($result->values)->toEqual($completions);
});

it('can handle completion complete request and return empty if no provider', function () {
    $promptName = 'no-provider-prompt';
    $promptSchema = PromptSchema::make($promptName, '', [PromptArgument::make('arg')]);
    $registeredPrompt = new RegisteredPrompt(
        schema: $promptSchema,
        handler: ['MyPromptHandler', 'get'],
        isManual: false,
        completionProviders: []
    );
    $this->registry->shouldReceive('getPrompt')->with($promptName)->andReturn($registeredPrompt);

    $request = CompletionCompleteRequest::make(1, PromptReference::make($promptName), ['name' => 'arg', 'value' => '']);
    $result = $this->dispatcher->handleCompletionComplete($request, $this->session);
    expect($result->values)->toBeEmpty();
});

it('can handle completion complete request with ListCompletionProvider instance', function () {
    $promptName = 'list-completion-prompt';
    $argName = 'category';
    $currentValue = 'bl';
    $expectedCompletions = ['blog'];

    $listProvider = new \PhpMcp\Server\Defaults\ListCompletionProvider(['blog', 'news', 'docs', 'api']);

    $promptSchema = PromptSchema::make($promptName, '', [PromptArgument::make($argName)]);
    $registeredPrompt = new RegisteredPrompt(
        schema: $promptSchema,
        handler: ['MyPromptHandler', 'get'],
        isManual: false,
        completionProviders: [$argName => $listProvider]
    );

    $this->registry->shouldReceive('getPrompt')->with($promptName)->andReturn($registeredPrompt);

    $request = CompletionCompleteRequest::make(1, PromptReference::make($promptName), ['name' => $argName, 'value' => $currentValue]);
    $result = $this->dispatcher->handleCompletionComplete($request, $this->session);

    expect($result->values)->toEqual($expectedCompletions);
    expect($result->total)->toBe(1);
    expect($result->hasMore)->toBeFalse();
});

it('can handle completion complete request with EnumCompletionProvider instance', function () {
    $promptName = 'enum-completion-prompt';
    $argName = 'status';
    $currentValue = 'a';
    $expectedCompletions = ['archived'];

    $enumProvider = new \PhpMcp\Server\Defaults\EnumCompletionProvider(StatusEnum::class);

    $promptSchema = PromptSchema::make($promptName, '', [PromptArgument::make($argName)]);
    $registeredPrompt = new RegisteredPrompt(
        schema: $promptSchema,
        handler: ['MyPromptHandler', 'get'],
        isManual: false,
        completionProviders: [$argName => $enumProvider]
    );

    $this->registry->shouldReceive('getPrompt')->with($promptName)->andReturn($registeredPrompt);

    $request = CompletionCompleteRequest::make(1, PromptReference::make($promptName), ['name' => $argName, 'value' => $currentValue]);
    $result = $this->dispatcher->handleCompletionComplete($request, $this->session);

    expect($result->values)->toEqual($expectedCompletions);
    expect($result->total)->toBe(1);
    expect($result->hasMore)->toBeFalse();
});


it('decodeCursor handles null and invalid cursors', function () {
    $method = new \ReflectionMethod(Dispatcher::class, 'decodeCursor');
    $method->setAccessible(true);

    expect($method->invoke($this->dispatcher, null))->toBe(0);
    expect($method->invoke($this->dispatcher, 'not_base64_$$$'))->toBe(0);
    expect($method->invoke($this->dispatcher, base64_encode('invalid_format')))->toBe(0);
    expect($method->invoke($this->dispatcher, base64_encode('offset=123')))->toBe(123);
});

it('encodeNextCursor generates correct cursor or null', function () {
    $method = new \ReflectionMethod(Dispatcher::class, 'encodeNextCursor');
    $method->setAccessible(true);
    $limit = DISPATCHER_PAGINATION_LIMIT;

    expect($method->invoke($this->dispatcher, 0, $limit, 10, $limit))->toBe(base64_encode('offset=3'));
    expect($method->invoke($this->dispatcher, 0, $limit, $limit, $limit))->toBeNull();
    expect($method->invoke($this->dispatcher, $limit, 2, $limit + 2 + 1, $limit))->toBe(base64_encode('offset=' . ($limit + 2)));
    expect($method->invoke($this->dispatcher, $limit, 1, $limit + 1, $limit))->toBeNull();
    expect($method->invoke($this->dispatcher, 0, 0, 10, $limit))->toBeNull();
});
