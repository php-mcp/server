<?php

namespace PhpMcp\Server\Tests\Transports;

use JsonException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PhpMcp\Server\Exceptions\McpException;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\Processor;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Transports\HttpTransportHandler;
use Psr\Log\LoggerInterface;
use RuntimeException;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    // Mock dependencies
    $this->processor = Mockery::mock(Processor::class);
    $this->transportState = Mockery::mock(TransportState::class);
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    // Create handler with mocked dependencies
    // Use partial mock to allow testing protected methods like sendSseEvent if needed,
    // but primarily focus on interactions with dependencies.
    $this->handler = Mockery::mock(HttpTransportHandler::class, [
        $this->processor,
        $this->transportState,
        $this->logger,
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    // Define test client ID
    $this->clientId = 'test_client_id';
});

// --- Initialization Tests ---

test('constructs with dependencies', function () {
    // Re-create without mock for this specific test
    $handler = new HttpTransportHandler(
        $this->processor,
        $this->transportState,
        $this->logger
    );

    expect($handler)->toBeInstanceOf(HttpTransportHandler::class);
});

test('start method throws exception', function () {
    expect(fn () => $this->handler->start())->toThrow(\Exception::class, 'This method should never be called');
});

// --- Request Handling Tests ---

test('handleInput processes JSON-RPC requests correctly', function () {
    $input = '{"jsonrpc":"2.0","id":1,"method":"test","params":{}}';
    $expectedRequest = Request::fromArray(json_decode($input, true));
    $expectedResponse = Response::success(new EmptyResult, 1);

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldReceive('process')
        ->once()
        ->with(Mockery::type(Request::class), $this->clientId)
        ->andReturn($expectedResponse);

    $this->transportState->shouldReceive('queueMessage')
        ->once()
        ->with($this->clientId, $expectedResponse);

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

    // No message queuing expected for notifications
    $this->transportState->shouldNotReceive('queueMessage');

    $this->handler->handleInput($input, $this->clientId);
});

test('handleInput handles invalid JSON properly', function () {
    $input = '{invalid json';

    $this->transportState->shouldReceive('updateClientActivity')->once()->with($this->clientId);
    $this->processor->shouldNotReceive('process');

    $this->transportState->shouldReceive('queueMessage')->once()->with(
        $this->clientId,
        Mockery::on(function ($response) {
            return $response instanceof Response &&
                   $response->error->code === McpException::CODE_PARSE_ERROR;
        })
    );

    $this->logger->shouldReceive('error')->with('MCP HTTP: JSON parse error', Mockery::any());

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

    $this->transportState->shouldReceive('queueMessage')->once()->with(
        $this->clientId,
        Mockery::on(function ($response) {
            return $response instanceof Response &&
                   $response->error->code === McpException::CODE_METHOD_NOT_FOUND;
        })
    );

    $this->logger->shouldReceive('error')->with('MCP HTTP: Request processing error', Mockery::any());

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

    $this->transportState->shouldReceive('queueMessage')->once()->with(
        $this->clientId,
        Mockery::on(function ($response) {
            return $response instanceof Response &&
                   $response->error->code === McpException::CODE_INTERNAL_ERROR;
        })
    );

    $this->logger->shouldReceive('error')->with('MCP HTTP: Unexpected error processing message', Mockery::any());

    $this->handler->handleInput($input, $this->clientId);
});

// --- Response Handling / SSE Streaming Tests ---

// Removed the old 'sendResponse queues message' test as it's no longer accurate.

test('handleSseConnection attempts to send initial endpoint event', function () {
    $postEndpointUri = '/mcp/post';

    // Mock the protected sendSseEvent method to prevent actual output and check call
    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'endpoint', $postEndpointUri)
        ->andReturnUsing(function () {
            // Simulate successful send of initial event
        });

    $this->logger->shouldReceive('info')->with('MCP: Starting SSE stream loop', Mockery::any());

    // Simulate loop exit after initial event
    $this->transportState->shouldReceive('getQueuedMessages')
        ->once()
        ->andThrow(new \Exception('Force loop exit')); // Force exit

    try {
        $this->handler->handleSseConnection(
            $this->clientId,
            $postEndpointUri
            // Using default intervals
        );
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('Force loop exit');
    }
});

test('handleSseConnection attempts to send queued messages', function () {
    $postEndpointUri = '/mcp/post';

    // Mock the protected sendSseEvent method
    $this->handler->shouldReceive('sendSseEvent')
        ->once() // Expect initial endpoint call
        ->with($this->clientId, 'endpoint', $postEndpointUri);

    // Setup three queued messages
    $queuedMessages = [
        ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
        ['jsonrpc' => '2.0', 'method' => 'notify', 'params' => []],
        ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['success' => true]],
    ];

    $this->transportState->shouldReceive('getQueuedMessages')
        ->once() // First call returns messages
        ->with($this->clientId)
        ->andReturn($queuedMessages);

    // Expect three message events calls via sendSseEvent (with event IDs 1, 2, and 3)
    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'message', json_encode($queuedMessages[0]), '1');

    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'message', json_encode($queuedMessages[1]), '2');

    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'message', json_encode($queuedMessages[2]), '3');

    // Simulate loop exit after processing messages
    $this->transportState->shouldReceive('getQueuedMessages')
        ->once() // Second call forces exit
        ->andThrow(new \Exception('Force loop exit'));

    try {
        $this->handler->handleSseConnection(
            $this->clientId,
            $postEndpointUri
            // Using default intervals
        );
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('Force loop exit');
    }
});

// Removed the 'sends ping events' test as the handler no longer sends pings.

test('handleSseConnection updates client activity based on interval', function () {
    $postEndpointUri = '/mcp/post';
    $loopInterval = 0.01; // 10ms
    $activityUpdateInterval = 0.02; // 20ms - should trigger on 3rd iteration

    // Mock sendSseEvent to prevent output
    $this->handler->shouldReceive('sendSseEvent')->byDefault(); // Allow any calls
    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'endpoint', $postEndpointUri);

    // No messages in the queue for simplicity
    $this->transportState->shouldReceive('getQueuedMessages')
        ->times(3) // Expect 3 iterations before activity update
        ->with($this->clientId)
        ->andReturn([]);

    // Expect client activity update on the iteration where time exceeds interval
    $this->transportState->shouldReceive('updateClientActivity')
        ->once()
        ->with($this->clientId);

    $this->logger->shouldReceive('debug')->with('MCP: Updated client activity timestamp', Mockery::any());

    // Force exit after the 3rd iteration (where activity was updated)
    $this->transportState->shouldReceive('getQueuedMessages')
        ->once()
        ->andThrow(new \Exception('Force loop exit'));

    try {
        // We need a way to control time or iterations reliably.
        // Mocking microtime is complex. Instead, we rely on the number of
        // getQueuedMessages calls to simulate loop progression.
        $this->handler->handleSseConnection(
            $this->clientId,
            $postEndpointUri,
            $loopInterval,
            $activityUpdateInterval
        );
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('Force loop exit');
    }
});

test('handleSseConnection handles initial sendSseEvent failure', function () {
    $postEndpointUri = '/mcp/post';

    // Mock the protected sendSseEvent method to throw on the first call
    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'endpoint', $postEndpointUri)
        ->andThrow(new RuntimeException('Simulated send failure'));

    $this->logger->shouldReceive('error')
        ->once()
        ->with('MCP: Failed to send initial endpoint event. Aborting stream.', Mockery::any());

    // Ensure the loop doesn't even start querying messages
    $this->transportState->shouldNotReceive('getQueuedMessages');

    // Execute - should catch the exception internally and log, not throw
    $this->handler->handleSseConnection(
        $this->clientId,
        $postEndpointUri
    );
});

test('handleSseConnection handles message encoding failure', function () {
    $postEndpointUri = '/mcp/post';

    // Mock sendSseEvent for the initial endpoint call (succeeds)
    $this->handler->shouldReceive('sendSseEvent')
        ->once()
        ->with($this->clientId, 'endpoint', $postEndpointUri);

    // Setup message that will fail json_encode (e.g., invalid UTF-8 or recursion)
    // Using a resource type which cannot be directly encoded.
    $badMessage = ['jsonrpc' => '2.0', 'id' => 1, 'result' => fopen('php://memory', 'r')];

    $this->transportState->shouldReceive('getQueuedMessages')
        ->once()
        ->with($this->clientId)
        ->andReturn([$badMessage]);

    // Expect sendSseEvent NOT to be called for the message due to encoding failure
    $this->handler->shouldNotReceive('sendSseEvent')
        ->with($this->clientId, 'message', Mockery::any(), '1');

    $this->logger->shouldReceive('error')
        ->once()
        ->with('MCP: Error sending message event via callback', Mockery::any());
    // The error message comes from the catch block wrapping the json_encode call

    // Ensure the loop exits after the encoding error
    $this->transportState->shouldNotReceive('getQueuedMessages');

    // Execute - should catch the exception internally and log, not throw
    $this->handler->handleSseConnection(
        $this->clientId,
        $postEndpointUri
    );
});

// Removed ping-related tests

// --- Client Cleanup Tests ---

test('cleanupClient removes client from transport state', function () {
    $this->transportState->shouldReceive('cleanupClient')
        ->once()
        ->with($this->clientId);

    $this->logger->shouldReceive('info')->never(); // cleanupClient no longer logs directly

    // Need to use the non-mocked handler for this test
    $handler = new HttpTransportHandler($this->processor, $this->transportState, $this->logger);
    $handler->cleanupClient($this->clientId);
});

// --- Error Handling Tests ---

test('handleError converts JsonException to parse error', function () {
    $exception = new JsonException('Invalid JSON');
    // Use the real handler instance for this utility method test
    $handler = new HttpTransportHandler($this->processor, $this->transportState, $this->logger);

    $result = $handler->handleError($exception);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->error->code)->toBe(McpException::CODE_PARSE_ERROR);
});

test('handleError preserves McpException error codes', function () {
    $exception = McpException::methodNotFound('Method not found');
    $handler = new HttpTransportHandler($this->processor, $this->transportState, $this->logger);

    $result = $handler->handleError($exception);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->error->code)->toBe(McpException::CODE_METHOD_NOT_FOUND);
});

test('handleError converts generic exceptions to internal error', function () {
    $exception = new RuntimeException('Unexpected error');
    $handler = new HttpTransportHandler($this->processor, $this->transportState, $this->logger);

    $result = $handler->handleError($exception);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->error->code)->toBe(McpException::CODE_INTERNAL_ERROR);
});
