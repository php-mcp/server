<?php

namespace PhpMcp\Server\Tests\Transports;

use JsonException;
use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Exceptions\McpException;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\Processor;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Tests\Mocks\StdioTransportHandlerMock;
use PhpMcp\Server\Transports\StdioTransportHandler;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use ReflectionClass;
use RuntimeException;

beforeEach(function () {
    // Mock dependencies
    $this->processor = Mockery::mock(Processor::class);
    $this->transportState = Mockery::mock(TransportState::class);
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    // Mock React components using interfaces
    $this->loop = Mockery::mock(LoopInterface::class);
    $this->inputStream = Mockery::mock(ReadableStreamInterface::class);
    $this->outputStream = Mockery::mock(WritableStreamInterface::class);

    // Create our test handler with mocked dependencies
    $this->handler = new StdioTransportHandlerMock(
        $this->processor,
        $this->transportState,
        $this->logger,
        $this->inputStream,
        $this->outputStream,
        $this->loop
    );

    // Define test client ID
    $this->clientId = 'stdio_client';
});

// --- Initialization and Start Tests ---

test('constructs with dependencies', function () {
    expect($this->handler)->toBeInstanceOf(StdioTransportHandler::class);
});

test('start handles fatal errors', function () {
    $exception = new RuntimeException('Fatal stream error');

    $this->loop->shouldReceive('addPeriodicTimer')->andThrow($exception);
    $this->logger->shouldReceive('critical')->once()->with('MCP: Fatal error in STDIO transport handler', Mockery::hasKey('exception'));

    $result = $this->handler->start();

    expect($result)->toBe(1); // Error exit code
});

// --- Message Handling Tests ---

test('handle adds to buffer and processes complete lines', function () {
    $input = '{"jsonrpc":"2.0","method":"test"}'."\n".'{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n";

    // Create a spy to track handleInput calls
    /** @var MockInterface&StdioTransportHandlerMock */
    $handlerSpy = Mockery::mock(StdioTransportHandlerMock::class.'[handleInput]', [
        $this->processor,
        $this->transportState,
        $this->logger,
        $this->inputStream,
        $this->outputStream,
        $this->loop,
    ])->makePartial();

    // Expect two calls to handleInput for the two complete lines
    $handlerSpy->shouldReceive('handleInput')->twice();
    $this->logger->shouldReceive('debug')->with('MCP: Received message', Mockery::any());

    $result = $handlerSpy->handle($input, $this->clientId);

    expect($result)->toBeTrue();
});

test('handle ignores empty lines', function () {
    $input = "\n\n\n";

    /** @var MockInterface&StdioTransportHandlerMock */
    $handlerSpy = Mockery::mock(StdioTransportHandlerMock::class.'[handleInput]', [
        $this->processor,
        $this->transportState,
        $this->logger,
        $this->inputStream,
        $this->outputStream,
        $this->loop,
    ])->makePartial();

    // Should not call handleInput
    $handlerSpy->shouldNotReceive('handleInput');

    $result = $handlerSpy->handle($input, $this->clientId);

    expect($result)->toBeTrue();
});

test('handleInput processes JSON-RPC requests correctly', function () {
    $input = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
    $expectedRequest = Request::fromArray(json_decode($input, true));
    // $expectedResponse = Response::result(['pong' => true], 1);
    $expectedResponse = Response::success(new EmptyResult, 1);

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Request::class), $this->clientId)
        ->andReturn($expectedResponse);

    $this->outputStream->shouldReceive('isWritable')->andReturn(true);
    $this->outputStream->shouldReceive('write')->once()->with(Mockery::pattern('/{"jsonrpc":"2.0".*}\n/'));
    $this->logger->shouldReceive('debug')->with('MCP: Sent response', Mockery::any());

    $this->handler->handleInput($input, $this->clientId);
});

test('handleInput processes JSON-RPC notifications correctly', function () {
    $input = '{"jsonrpc":"2.0","method":"initialized","params":{}}';
    $expectedNotification = Notification::fromArray(json_decode($input, true));

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Notification::class), $this->clientId)
        ->andReturn(null); // Notifications typically return null

    // No output expected for notifications
    $this->outputStream->shouldNotReceive('write');

    $this->handler->handleInput($input, $this->clientId);
});

test('handleInput handles invalid JSON properly', function () {
    $input = '{invalid json';

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldNotReceive('process');

    $this->outputStream->shouldReceive('isWritable')->andReturn(true);
    $this->outputStream->shouldReceive('write')->once()->with(
        Mockery::pattern('/{"jsonrpc":"2.0","id":1,"error":{"code":-32700.*}\n/')
    );
    $this->logger->shouldReceive('error')->with('MCP: Error processing message:', Mockery::any());

    $this->handler->handleInput($input, $this->clientId);
});

test('handleInput handles McpException properly', function () {
    $input = '{"jsonrpc":"2.0","id":1,"method":"invalid_method"}';
    $exception = McpException::methodNotFound('Method not found');

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Request::class), $this->clientId)
        ->andThrow($exception);

    $this->outputStream->shouldReceive('isWritable')->andReturn(true);
    $this->outputStream->shouldReceive('write')->once()->with(
        Mockery::pattern('/{"jsonrpc":"2.0","id":1,"error":{"code":-32601.*}\n/')
    );
    $this->logger->shouldReceive('error')->with('MCP: Error processing message:', Mockery::any());

    $this->handler->handleInput($input, $this->clientId);
});

test('handleInput handles generic exceptions properly', function () {
    $input = '{"jsonrpc":"2.0","id":1,"method":"throws_error"}';
    $exception = new RuntimeException('Unexpected error');

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Request::class), $this->clientId)
        ->andThrow($exception);

    $this->outputStream->shouldReceive('isWritable')->andReturn(true);
    $this->outputStream->shouldReceive('write')->once()->with(
        Mockery::pattern('/{"jsonrpc":"2.0","id":1,"error":{"code":-32603.*}\n/')
    );
    $this->logger->shouldReceive('error')->with('MCP: Error processing message:', Mockery::any());

    $this->handler->handleInput($input, $this->clientId);
});

// --- Error Handling Tests ---

test('handleError converts JsonException to parse error', function () {
    $exception = new JsonException('Invalid JSON');

    $this->logger->shouldReceive('error')->with('MCP: Transport Error', Mockery::any());

    $result = $this->handler->handleError($exception, 123);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->error->code)->toBe(McpException::CODE_PARSE_ERROR);
    expect($result->id)->toBe(123);
});

test('handleError preserves McpException error codes', function () {
    $exception = McpException::methodNotFound('Method not found');

    $this->logger->shouldReceive('error')->with('MCP: Transport Error', Mockery::any());

    $result = $this->handler->handleError($exception, 456);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->error->code)->toBe(McpException::CODE_METHOD_NOT_FOUND);
    expect($result->id)->toBe(456);
});

test('handleError converts generic exceptions to internal error', function () {
    $exception = new RuntimeException('Unexpected error');

    $this->logger->shouldReceive('error')->with('MCP: Transport Error', Mockery::any());

    $result = $this->handler->handleError($exception, 789);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->error->code)->toBe(McpException::CODE_INTERNAL_ERROR);
    expect($result->id)->toBe(789);
});

// --- Shutdown and Cleanup Tests ---

test('stop closes streams and stops loop', function () {
    $this->logger->shouldReceive('info')->with('MCP: Closing STDIO Transport.');
    $this->transportState->shouldReceive('cleanupClient')->once()->with($this->clientId);

    $this->inputStream->shouldReceive('close')->once();
    $this->outputStream->shouldReceive('close')->once();
    $this->loop->shouldReceive('stop')->once();

    $this->handler->stop();
});

// --- Queued Messages Tests ---

test('checkQueuedMessages processes and sends queued messages', function () {
    $queuedMessages = [
        ['jsonrpc' => '2.0', 'method' => 'notification1', 'params' => []],
        ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['success' => true]],
    ];

    $this->transportState->shouldReceive('getQueuedMessages')
        ->once()
        ->with($this->clientId)
        ->andReturn($queuedMessages);

    $this->outputStream->shouldReceive('isWritable')->times(2)->andReturn(true);
    $this->outputStream->shouldReceive('write')->twice()->with(Mockery::pattern('/{"jsonrpc":"2.0".*}\n/'));
    $this->logger->shouldReceive('debug')->twice()->with('MCP: Sent message from queue', Mockery::any());

    // Use reflection to call protected method
    $reflection = new ReflectionClass($this->handler);
    $method = $reflection->getMethod('checkQueuedMessages');
    $method->setAccessible(true);
    $method->invoke($this->handler);
});

test('checkQueuedMessages handles empty queue gracefully', function () {
    $this->transportState->shouldReceive('getQueuedMessages')
        ->once()
        ->with($this->clientId)
        ->andReturn([]);

    $this->outputStream->shouldNotReceive('write');

    $reflection = new ReflectionClass($this->handler);
    $method = $reflection->getMethod('checkQueuedMessages');
    $method->setAccessible(true);
    $method->invoke($this->handler);
});

test('checkQueuedMessages handles queue errors gracefully', function () {
    $exception = new RuntimeException('Queue error');

    $this->transportState->shouldReceive('getQueuedMessages')
        ->once()
        ->with($this->clientId)
        ->andThrow($exception);

    $this->logger->shouldReceive('error')->once()->with('MCP: Error processing or sending queued messages', Mockery::any());

    $reflection = new ReflectionClass($this->handler);
    $method = $reflection->getMethod('checkQueuedMessages');
    $method->setAccessible(true);
    $method->invoke($this->handler);
});
