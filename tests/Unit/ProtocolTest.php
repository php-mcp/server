<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Schema\Implementation;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Schema\JsonRpc\BatchResponse;
use PhpMcp\Schema\JsonRpc\Error;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Response;
use PhpMcp\Schema\Notification\ResourceListChangedNotification;
use PhpMcp\Schema\Notification\ResourceUpdatedNotification;
use PhpMcp\Schema\Notification\ToolListChangedNotification;
use PhpMcp\Schema\Result\EmptyResult;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Session\SubscriptionManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\LoopInterface;

use function React\Async\await;
use function React\Promise\resolve;
use function React\Promise\reject;

const SESSION_ID = 'session-test-789';
const SUPPORTED_VERSION_PROTO = Protocol::LATEST_PROTOCOL_VERSION;
const SERVER_NAME_PROTO = 'Test Protocol Server';
const SERVER_VERSION_PROTO = '0.3.0';

function createRequest(string $method, array $params = [], string|int $id = 'req-proto-1'): Request
{
    return new Request('2.0', $id, $method, $params);
}

function createNotification(string $method, array $params = []): Notification
{
    return new Notification('2.0', $method, $params);
}

function expectErrorResponse(mixed $response, int $expectedCode, string|int|null $expectedId = 'req-proto-1'): void
{
    test()->expect($response)->toBeInstanceOf(Error::class);
    test()->expect($response->id)->toBe($expectedId);
    test()->expect($response->code)->toBe($expectedCode);
    test()->expect($response->jsonrpc)->toBe('2.0');
}

function expectSuccessResponse(mixed $response, mixed $expectedResult, string|int|null $expectedId = 'req-proto-1'): void
{
    test()->expect($response)->toBeInstanceOf(Response::class);
    test()->expect($response->id)->toBe($expectedId);
    test()->expect($response->jsonrpc)->toBe('2.0');
    test()->expect($response->result)->toBe($expectedResult);
}


beforeEach(function () {
    /** @var MockInterface&Registry $registry */
    $this->registry = Mockery::mock(Registry::class);
    /** @var MockInterface&SessionManager $sessionManager */
    $this->sessionManager = Mockery::mock(SessionManager::class);
    /** @var MockInterface&Dispatcher $dispatcher */
    $this->dispatcher = Mockery::mock(Dispatcher::class);
    /** @var MockInterface&SubscriptionManager $subscriptionManager */
    $this->subscriptionManager = Mockery::mock(SubscriptionManager::class);
    /** @var MockInterface&LoggerInterface $logger */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    /** @var MockInterface&ServerTransportInterface $transport */
    $this->transport = Mockery::mock(ServerTransportInterface::class);
    /** @var MockInterface&SessionInterface $session */
    $this->session = Mockery::mock(SessionInterface::class);

    /** @var MockInterface&LoopInterface $loop */
    $loop = Mockery::mock(LoopInterface::class);
    /** @var MockInterface&CacheInterface $cache */
    $cache = Mockery::mock(CacheInterface::class);
    /** @var MockInterface&ContainerInterface $container */
    $container = Mockery::mock(ContainerInterface::class);

    $this->configuration = new Configuration(
        serverInfo: Implementation::make(SERVER_NAME_PROTO, SERVER_VERSION_PROTO),
        capabilities: ServerCapabilities::make(),
        logger: $this->logger,
        loop: $loop,
        cache: $cache,
        container: $container
    );

    $this->sessionManager->shouldReceive('getSession')->with(SESSION_ID)->andReturn($this->session)->byDefault();
    $this->sessionManager->shouldReceive('on')->withAnyArgs()->byDefault();

    $this->registry->shouldReceive('on')->withAnyArgs()->byDefault();

    $this->session->shouldReceive('get')->with('initialized', false)->andReturn(true)->byDefault();
    $this->session->shouldReceive('save')->byDefault();

    $this->transport->shouldReceive('on')->withAnyArgs()->byDefault();
    $this->transport->shouldReceive('removeListener')->withAnyArgs()->byDefault();
    $this->transport->shouldReceive('sendMessage')
        ->withAnyArgs()
        ->andReturn(resolve(null))
        ->byDefault();

    $this->protocol = new Protocol(
        $this->configuration,
        $this->registry,
        $this->sessionManager,
        $this->dispatcher,
        $this->subscriptionManager
    );

    $this->protocol->bindTransport($this->transport);
});

it('listens to SessionManager events on construction', function () {
    $this->sessionManager->shouldHaveReceived('on')->with('session_deleted', Mockery::type('callable'));
});

it('listens to Registry events on construction', function () {
    $this->registry->shouldHaveReceived('on')->with('list_changed', Mockery::type('callable'));
});

it('binds to a transport and attaches listeners', function () {
    $newTransport = Mockery::mock(ServerTransportInterface::class);
    $newTransport->shouldReceive('on')->with('message', Mockery::type('callable'))->once();
    $newTransport->shouldReceive('on')->with('client_connected', Mockery::type('callable'))->once();
    $newTransport->shouldReceive('on')->with('client_disconnected', Mockery::type('callable'))->once();
    $newTransport->shouldReceive('on')->with('error', Mockery::type('callable'))->once();

    $this->protocol->bindTransport($newTransport);
});

it('unbinds from a previous transport when binding a new one', function () {
    $this->transport->shouldReceive('removeListener')->times(4);

    $newTransport = Mockery::mock(ServerTransportInterface::class);
    $newTransport->shouldReceive('on')->times(4);

    $this->protocol->bindTransport($newTransport);
});

it('unbinds transport and removes listeners', function () {
    $this->transport->shouldReceive('removeListener')->with('message', Mockery::type('callable'))->once();
    $this->transport->shouldReceive('removeListener')->with('client_connected', Mockery::type('callable'))->once();
    $this->transport->shouldReceive('removeListener')->with('client_disconnected', Mockery::type('callable'))->once();
    $this->transport->shouldReceive('removeListener')->with('error', Mockery::type('callable'))->once();

    $this->protocol->unbindTransport();

    $reflection = new \ReflectionClass($this->protocol);
    $transportProp = $reflection->getProperty('transport');
    $transportProp->setAccessible(true);
    expect($transportProp->getValue($this->protocol))->toBeNull();
});

it('processes a valid Request message', function () {
    $request = createRequest('test/method', ['param' => 1]);
    $result = new EmptyResult();
    $expectedResponse = Response::make($request->id, $result);

    $this->dispatcher->shouldReceive('handleRequest')->once()
        ->with(Mockery::on(fn ($arg) => $arg instanceof Request && $arg->method === 'test/method'), $this->session)
        ->andReturn($result);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(fn ($arg) => $arg instanceof Response && $arg->id === $request->id && $arg->result === $result), SESSION_ID, Mockery::any())
        ->andReturn(resolve(null));

    $this->protocol->processMessage($request, SESSION_ID);
    $this->session->shouldHaveReceived('save');
});

it('processes a valid Notification message', function () {
    $notification = createNotification('test/notify', ['data' => 'info']);

    $this->dispatcher->shouldReceive('handleNotification')->once()
        ->with(Mockery::on(fn ($arg) => $arg instanceof Notification && $arg->method === 'test/notify'), $this->session)
        ->andReturnNull();

    $this->transport->shouldNotReceive('sendMessage');

    $this->protocol->processMessage($notification, SESSION_ID);
    $this->session->shouldHaveReceived('save');
});

it('processes a BatchRequest with mixed requests and notifications', function () {
    $req1 = createRequest('req/1', [], 'batch-id-1');
    $notif1 = createNotification('notif/1');
    $req2 = createRequest('req/2', [], 'batch-id-2');
    $batchRequest = new BatchRequest([$req1, $notif1, $req2]);

    $result1 = new EmptyResult();
    $result2 = new EmptyResult();

    $this->dispatcher->shouldReceive('handleRequest')->once()->with(Mockery::on(fn (Request $r) => $r->id === 'batch-id-1'), $this->session)->andReturn($result1);
    $this->dispatcher->shouldReceive('handleNotification')->once()->with(Mockery::on(fn (Notification $n) => $n->method === 'notif/1'), $this->session);
    $this->dispatcher->shouldReceive('handleRequest')->once()->with(Mockery::on(fn (Request $r) => $r->id === 'batch-id-2'), $this->session)->andReturn($result2);


    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(function (BatchResponse $response) use ($req1, $req2, $result1, $result2) {
            expect(count($response->items))->toBe(2);
            expect($response->items[0]->id)->toBe($req1->id);
            expect($response->items[0]->result)->toBe($result1);
            expect($response->items[1]->id)->toBe($req2->id);
            expect($response->items[1]->result)->toBe($result2);
            return true;
        }), SESSION_ID, Mockery::any())
        ->andReturn(resolve(null));

    $this->protocol->processMessage($batchRequest, SESSION_ID);
    $this->session->shouldHaveReceived('save');
});

it('processes a BatchRequest with only notifications and sends no response', function () {
    $notif1 = createNotification('notif/only1');
    $notif2 = createNotification('notif/only2');
    $batchRequest = new BatchRequest([$notif1, $notif2]);

    $this->dispatcher->shouldReceive('handleNotification')->twice();
    $this->transport->shouldNotReceive('sendMessage');

    $this->protocol->processMessage($batchRequest, SESSION_ID);
    $this->session->shouldHaveReceived('save');
});


it('sends error response if session is not found', function () {
    $request = createRequest('test/method');
    $this->sessionManager->shouldReceive('getSession')->with('unknown-client')->andReturn(null);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(function (Error $error) use ($request) {
            expectErrorResponse($error, \PhpMcp\Schema\Constants::INVALID_REQUEST, $request->id);
            expect($error->message)->toContain('Invalid or expired session');
            return true;
        }), 'unknown-client', ['status_code' => 404, 'is_initialize_request' => false])
        ->andReturn(resolve(null));

    $this->protocol->processMessage($request, 'unknown-client', ['is_initialize_request' => false]);
    $this->session->shouldNotHaveReceived('save');
});

it('sends error response if session is not initialized for non-initialize request', function () {
    $request = createRequest('tools/list');
    $this->session->shouldReceive('get')->with('initialized', false)->andReturn(false);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(function (Error $error) use ($request) {
            expectErrorResponse($error, \PhpMcp\Schema\Constants::INVALID_REQUEST, $request->id);
            expect($error->message)->toContain('Client session not initialized');
            return true;
        }), SESSION_ID, Mockery::any())
        ->andReturn(resolve(null));

    $this->protocol->processMessage($request, SESSION_ID);
});

it('sends error response if capability for request method is disabled', function () {
    $request = createRequest('tools/list');
    $configuration = new Configuration(
        serverInfo: $this->configuration->serverInfo,
        capabilities: ServerCapabilities::make(tools: false),
        logger: $this->logger,
        loop: $this->configuration->loop,
        cache: $this->configuration->cache,
        container: $this->configuration->container,
    );

    $protocol = new Protocol(
        $configuration,
        $this->registry,
        $this->sessionManager,
        $this->dispatcher,
        $this->subscriptionManager
    );

    $protocol->bindTransport($this->transport);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(function (Error $error) use ($request) {
            expectErrorResponse($error, \PhpMcp\Schema\Constants::METHOD_NOT_FOUND, $request->id);
            expect($error->message)->toContain('Tools are not enabled');
            return true;
        }), SESSION_ID, Mockery::any())
        ->andReturn(resolve(null));

    $protocol->processMessage($request, SESSION_ID);
});

it('sends exceptions thrown while handling request as JSON-RPC error', function () {
    $request = createRequest('fail/method');
    $exception = McpServerException::methodNotFound('fail/method');

    $this->dispatcher->shouldReceive('handleRequest')->once()->andThrow($exception);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(function (Error $error) use ($request) {
            expectErrorResponse($error, \PhpMcp\Schema\Constants::METHOD_NOT_FOUND, $request->id);
            expect($error->message)->toContain('Method not found');
            return true;
        }), SESSION_ID, Mockery::any())
        ->andReturn(resolve(null));

    $this->protocol->processMessage($request, SESSION_ID);


    $request = createRequest('explode/method');
    $exception = new \RuntimeException('Something bad happened');

    $this->dispatcher->shouldReceive('handleRequest')->once()->andThrow($exception);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with(Mockery::on(function (Error $error) use ($request) {
            expectErrorResponse($error, \PhpMcp\Schema\Constants::INTERNAL_ERROR, $request->id);
            expect($error->message)->toContain('Internal error processing method explode/method');
            expect($error->data)->toBe('Something bad happened');
            return true;
        }), SESSION_ID, Mockery::any())
        ->andReturn(resolve(null));

    $this->protocol->processMessage($request, SESSION_ID);
});

it('sends a notification successfully', function () {
    $notification = createNotification('event/occurred', ['value' => true]);

    $this->transport->shouldReceive('sendMessage')->once()
        ->with($notification, SESSION_ID, [])
        ->andReturn(resolve(null));

    $promise = $this->protocol->sendNotification($notification, SESSION_ID);
    await($promise);
});

it('rejects sending notification if transport not bound', function () {
    $this->protocol->unbindTransport();
    $notification = createNotification('event/occurred');

    $promise = $this->protocol->sendNotification($notification, SESSION_ID);

    await($promise->then(null, function (McpServerException $e) {
        expect($e->getMessage())->toContain('Transport not bound');
    }));
});

it('rejects sending notification if transport send fails', function () {
    $notification = createNotification('event/occurred');
    $transportException = new \PhpMcp\Server\Exception\TransportException('Send failed');
    $this->transport->shouldReceive('sendMessage')->once()->andReturn(reject($transportException));

    $promise = $this->protocol->sendNotification($notification, SESSION_ID);
    await($promise->then(null, function (McpServerException $e) use ($transportException) {
        expect($e->getMessage())->toContain('Failed to send notification: Send failed');
        expect($e->getPrevious())->toBe($transportException);
    }));
});

it('notifies resource updated to subscribers', function () {
    $uri = 'test://resource/123';
    $subscribers = ['client-sub-1', 'client-sub-2'];
    $this->subscriptionManager->shouldReceive('getSubscribers')->with($uri)->andReturn($subscribers);

    $expectedNotification = ResourceUpdatedNotification::make($uri);

    $this->transport->shouldReceive('sendMessage')->twice()
        ->with(Mockery::on(function (Notification $notification) use ($expectedNotification) {
            expect($notification->method)->toBe($expectedNotification->method);
            expect($notification->params)->toBe($expectedNotification->params);
            return true;
        }), Mockery::anyOf(...$subscribers), [])
        ->andReturn(resolve(null));

    $this->protocol->notifyResourceUpdated($uri);
});

it('handles client connected event', function () {
    $this->logger->shouldReceive('info')->with('Client connected', ['sessionId' => SESSION_ID])->once();
    $this->sessionManager->shouldReceive('createSession')->with(SESSION_ID)->once();

    $this->protocol->handleClientConnected(SESSION_ID);
});

it('handles client disconnected event', function () {
    $reason = 'Connection closed';
    $this->logger->shouldReceive('info')->with('Client disconnected', ['clientId' => SESSION_ID, 'reason' => $reason])->once();
    $this->sessionManager->shouldReceive('deleteSession')->with(SESSION_ID)->once();

    $this->protocol->handleClientDisconnected(SESSION_ID, $reason);
});

it('handles transport error event with client ID', function () {
    $error = new \RuntimeException('Socket error');
    $this->logger->shouldReceive('error')
        ->with('Transport error for client', ['error' => 'Socket error', 'exception_class' => \RuntimeException::class, 'clientId' => SESSION_ID])
        ->once();

    $this->protocol->handleTransportError($error, SESSION_ID);
});

it('handles transport error event without client ID', function () {
    $error = new \RuntimeException('Listener setup failed');
    $this->logger->shouldReceive('error')
        ->with('General transport error', ['error' => 'Listener setup failed', 'exception_class' => \RuntimeException::class])
        ->once();

    $this->protocol->handleTransportError($error, null);
});

it('handles list changed event from registry and notifies subscribers', function (string $listType, string $expectedNotificationClass) {
    $listChangeUri = "mcp://changes/{$listType}";
    $subscribers = ['client-sub-A', 'client-sub-B'];

    $this->subscriptionManager->shouldReceive('getSubscribers')->with($listChangeUri)->andReturn($subscribers);
    $capabilities = ServerCapabilities::make(
        toolsListChanged: true,
        resourcesListChanged: true,
        promptsListChanged: true,
    );

    $configuration = new Configuration(
        serverInfo: $this->configuration->serverInfo,
        capabilities: $capabilities,
        logger: $this->logger,
        loop: $this->configuration->loop,
        cache: $this->configuration->cache,
        container: $this->configuration->container,
    );

    $protocol = new Protocol(
        $configuration,
        $this->registry,
        $this->sessionManager,
        $this->dispatcher,
        $this->subscriptionManager
    );

    $protocol->bindTransport($this->transport);

    $this->transport->shouldReceive('sendMessage')
        ->with(Mockery::type($expectedNotificationClass), Mockery::anyOf(...$subscribers), [])
        ->times(count($subscribers))
        ->andReturn(resolve(null));

    $protocol->handleListChanged($listType);
})->with([
    'tools' => ['tools', ToolListChangedNotification::class],
    'resources' => ['resources', ResourceListChangedNotification::class],
]);

it('does not send list changed notification if capability is disabled', function (string $listType) {
    $listChangeUri = "mcp://changes/{$listType}";
    $subscribers = ['client-sub-A'];
    $this->subscriptionManager->shouldReceive('getSubscribers')->with($listChangeUri)->andReturn($subscribers);

    $caps = ServerCapabilities::make(
        toolsListChanged: $listType !== 'tools',
        resourcesListChanged: $listType !== 'resources',
        promptsListChanged: $listType !== 'prompts',
    );

    $configuration = new Configuration(
        serverInfo: $this->configuration->serverInfo,
        capabilities: $caps,
        logger: $this->logger,
        loop: $this->configuration->loop,
        cache: $this->configuration->cache,
        container: $this->configuration->container,
    );

    $protocol = new Protocol(
        $configuration,
        $this->registry,
        $this->sessionManager,
        $this->dispatcher,
        $this->subscriptionManager
    );

    $protocol->bindTransport($this->transport);
    $this->transport->shouldNotReceive('sendMessage');
})->with(['tools', 'resources', 'prompts',]);


it('allows initialize request when session not initialized', function () {
    $request = createRequest('initialize', ['protocolVersion' => SUPPORTED_VERSION_PROTO]);
    $this->session->shouldReceive('get')->with('initialized', false)->andReturn(false);

    $this->dispatcher->shouldReceive('handleRequest')->once()
        ->with(Mockery::type(Request::class), $this->session)
        ->andReturn(new EmptyResult());

    $this->transport->shouldReceive('sendMessage')->once()
        ->andReturn(resolve(null));

    $this->protocol->processMessage($request, SESSION_ID);
});

it('allows initialize and ping regardless of capabilities', function (string $method) {
    $request = createRequest($method);
    $capabilities = ServerCapabilities::make(
        tools: false,
        resources: false,
        prompts: false,
        logging: false,
    );
    $configuration = new Configuration(
        serverInfo: $this->configuration->serverInfo,
        capabilities: $capabilities,
        logger: $this->logger,
        loop: $this->configuration->loop,
        cache: $this->configuration->cache,
        container: $this->configuration->container,
    );

    $protocol = new Protocol(
        $configuration,
        $this->registry,
        $this->sessionManager,
        $this->dispatcher,
        $this->subscriptionManager
    );

    $protocol->bindTransport($this->transport);

    $this->dispatcher->shouldReceive('handleRequest')->once()->andReturn(new EmptyResult());
    $this->transport->shouldReceive('sendMessage')->once()
        ->andReturn(resolve(null));

    $protocol->processMessage($request, SESSION_ID);
})->with(['initialize', 'ping']);
