<?php

namespace PhpMcp\Server\Tests\Unit; // Correct namespace

use Mockery;
// Use Mockery integration trait
use Mockery\MockInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\Processor;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\State\ClientStateManager;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

use function React\Async\await;
use function React\Promise\resolve;

beforeEach(function () {
    $this->processor = Mockery::mock(Processor::class);
    $this->clientStateManager = Mockery::mock(ClientStateManager::class);
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->loop = Loop::get();
    $this->transport = Mockery::mock(ServerTransportInterface::class);

    $this->handler = new Protocol(
        $this->processor,
        $this->clientStateManager,
        $this->logger,
        $this->loop
    );

    $this->transport->shouldReceive('on')->withAnyArgs()->byDefault();
    $this->transport->shouldReceive('removeListener')->withAnyArgs()->byDefault();
    $this->transport->shouldReceive('sendToClientAsync')
        ->withAnyArgs()
        ->andReturn(resolve(null))
        ->byDefault();

    $this->handler->bindTransport($this->transport);
});

afterEach(function () {
    Mockery::close();
});

it('can handle a valid request', function () {
    $clientId = 'client-req-1';
    $requestId = 123;
    $method = 'test/method';
    $params = ['a' => 1];
    $rawJson = json_encode(['jsonrpc' => '2.0', 'id' => $requestId, 'method' => $method, 'params' => $params]);
    $expectedResponse = Response::success(new EmptyResult, $requestId);
    $expectedResponseJson = json_encode($expectedResponse->toArray());

    $this->processor->shouldReceive('process')->once()->with(Mockery::type(Request::class), $clientId)->andReturn($expectedResponse);
    $this->transport->shouldReceive('sendToClientAsync')->once()->with($clientId, $expectedResponseJson."\n")->andReturn(resolve(null));

    $this->handler->handleRawMessage($rawJson, $clientId);
    // Mockery verifies calls
});

it('can handle a valid notification', function () {
    $clientId = 'client-notif-1';
    $method = 'notify/event';
    $params = ['b' => 2];
    $rawJson = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);

    $this->processor->shouldReceive('process')->once()->with(Mockery::type(Notification::class), $clientId)->andReturn(null);
    $this->transport->shouldNotReceive('sendToClientAsync');

    $this->handler->handleRawMessage($rawJson, $clientId);
});

it('sends a parse error response for invalid JSON', function () {
    $clientId = 'client-err-parse';
    $rawJson = '{"jsonrpc":"2.0", "id":';

    $this->processor->shouldNotReceive('process');
    $this->transport->shouldReceive('sendToClientAsync')->once()->with($clientId, Mockery::on(fn ($json) => str_contains($json, '"code":-32700') && str_contains($json, '"id":null')))->andReturn(resolve(null));

    $this->handler->handleRawMessage($rawJson, $clientId);
});

it('sends an invalid request error response for a request with missing method', function () {
    $clientId = 'client-err-invalid';
    $rawJson = '{"jsonrpc":"2.0", "id": 456}'; // Missing method

    $this->processor->shouldNotReceive('process');
    $this->transport->shouldReceive('sendToClientAsync')->once()->with($clientId, Mockery::on(fn ($json) => str_contains($json, '"code":-32600') && str_contains($json, '"id":456')))->andReturn(resolve(null));

    $this->handler->handleRawMessage($rawJson, $clientId);
});

it('sends a mcp error response for a method not found', function () {
    $clientId = 'client-err-mcp';
    $requestId = 789;
    $method = 'nonexistent/method';
    $rawJson = json_encode(['jsonrpc' => '2.0', 'id' => $requestId, 'method' => $method]);
    $mcpException = McpServerException::methodNotFound($method);

    $this->processor->shouldReceive('process')->once()->andThrow($mcpException);
    $this->transport->shouldReceive('sendToClientAsync')->once()->with($clientId, Mockery::on(fn ($json) => str_contains($json, '"code":-32601') && str_contains($json, '"id":789')))->andReturn(resolve(null));

    $this->handler->handleRawMessage($rawJson, $clientId);
});

it('sends an internal error response on processor exception', function () {
    $clientId = 'client-err-internal';
    $requestId = 101;
    $method = 'explode/now';
    $rawJson = json_encode(['jsonrpc' => '2.0', 'id' => $requestId, 'method' => $method]);
    $internalException = new \RuntimeException('Borked');

    $this->processor->shouldReceive('process')->once()->andThrow($internalException);
    $this->transport->shouldReceive('sendToClientAsync')->once()->with($clientId, Mockery::on(fn ($json) => str_contains($json, '"code":-32603') && str_contains($json, '"id":101')))->andReturn(resolve(null));

    $this->handler->handleRawMessage($rawJson, $clientId);
});

// --- Test Event Handlers (Now call the handler directly) ---

it('logs info when a client connects', function () {
    $clientId = 'client-connect-test';
    $this->logger->shouldReceive('info')->once()->with('Client connected', ['clientId' => $clientId]);
    $this->handler->handleClientConnected($clientId); // Call method directly
});

it('cleans up state when a client disconnects', function () {
    $clientId = 'client-disconnect-test';
    $reason = 'Connection closed by peer';

    $this->logger->shouldReceive('info')->once()->with('Client disconnected', ['clientId' => $clientId, 'reason' => $reason]);
    $this->clientStateManager->shouldReceive('cleanupClient')->once()->with($clientId);

    $this->handler->handleClientDisconnected($clientId, $reason); // Call method directly
});

it('cleans up client state when a transport error occurs', function () {
    $clientId = 'client-transporterror-test';
    $error = new \RuntimeException('Socket error');

    $this->logger->shouldReceive('error')->once()->with('Transport error for client', Mockery::any());
    $this->clientStateManager->shouldReceive('cleanupClient')->once()->with($clientId);

    $this->handler->handleTransportError($error, $clientId); // Call method directly
});

it('logs a general error when a transport error occurs', function () {
    $error = new \RuntimeException('Listener setup failed');

    $this->logger->shouldReceive('error')->once()->with('General transport error', Mockery::any());
    $this->clientStateManager->shouldNotReceive('cleanupClient');

    $this->handler->handleTransportError($error, null); // Call method directly
});

// --- Test Binding/Unbinding ---

it('attaches listeners when binding a new transport', function () {
    $newTransport = Mockery::mock(ServerTransportInterface::class);
    $newTransport->shouldReceive('on')->times(4);
    $this->handler->bindTransport($newTransport);
    expect(true)->toBeTrue();
});

it('removes listeners when unbinding a transport', function () {
    $this->transport->shouldReceive('on')->times(4);
    $this->handler->bindTransport($this->transport);
    $this->transport->shouldReceive('removeListener')->times(4);
    $this->handler->unbindTransport();
    expect(true)->toBeTrue();
});

it('unbinds previous transport when binding a new one', function () {
    $transport1 = Mockery::mock(ServerTransportInterface::class);
    $transport2 = Mockery::mock(ServerTransportInterface::class);
    $transport1->shouldReceive('on')->times(4);
    $this->handler->bindTransport($transport1);
    $transport1->shouldReceive('removeListener')->times(4);
    $transport2->shouldReceive('on')->times(4);
    $this->handler->bindTransport($transport2);
    expect(true)->toBeTrue();
});

it('encodes and sends a notification', function () {
    $clientId = 'client-send-notif';
    $notification = new Notification('2.0', 'state/update', ['value' => true]);
    $expectedJson = json_encode($notification->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $expectedFrame = $expectedJson."\n";

    $this->transport->shouldReceive('sendToClientAsync')
        ->once()
        ->with($clientId, $expectedFrame)
        ->andReturn(resolve(null));

    $promise = $this->handler->sendNotification($clientId, $notification);
    await($promise);

    expect(true)->toBeTrue();

})->group('usesLoop');

it('rejects on encoding error when sending a notification', function () {
    $clientId = 'client-send-notif-err';
    $resource = fopen('php://memory', 'r'); // Unencodable resource
    $notification = new Notification('2.0', 'bad/data', ['res' => $resource]);

    $this->transport->shouldNotReceive('sendToClientAsync');

    // Act
    $promise = $this->handler->sendNotification($clientId, $notification);
    await($promise);

    if (is_resource($resource)) {
        fclose($resource);
    }

})->group('usesLoop')->throws(McpServerException::class, 'Failed to encode notification');

it('rejects if transport not bound when sending a notification', function () {
    $this->handler->unbindTransport();
    $notification = new Notification('2.0', 'test');

    $promise = $this->handler->sendNotification('client-id', $notification);
    await($promise);

})->throws(McpServerException::class, 'Transport not bound');
