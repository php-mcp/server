<?php

namespace PhpMcp\Server\Tests\Unit\Transports;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Server\Transports\HttpServerTransport;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\Io\BufferedBody;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;

use function React\Async\await;

// --- Constants ---
const HOST = '127.0.0.1';
const PORT = 8990;
const PREFIX = 'mcp_test';
const SSE_PATH = '/mcp_test/sse';
const MSG_PATH = '/mcp_test/message';
const BASE_URL = 'http://'.HOST.':'.PORT;

// --- Setup ---
beforeEach(function () {

    $this->loop = Loop::get();
    /** @var LoggerInterface&MockInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    $this->transport = new HttpServerTransport(HOST, PORT, PREFIX);
    $this->transport->setLogger($this->logger);
    $this->transport->setLoop($this->loop);

    // Extract the request handler logic for direct testing
    $reflector = new \ReflectionClass($this->transport);
    $method = $reflector->getMethod('createRequestHandler');
    $method->setAccessible(true);
    $this->requestHandler = $method->invoke($this->transport);

    // Reset internal state relevant to tests
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, []);

    $listeningProp = $reflector->getProperty('listening');
    $listeningProp->setAccessible(true);
    $listeningProp->setValue($this->transport, true);

    $closingProp = $reflector->getProperty('closing');
    $closingProp->setAccessible(true);
    $closingProp->setValue($this->transport, false);

    $socketProp = $reflector->getProperty('socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($this->transport, null);

    $httpProp = $reflector->getProperty('http');
    $httpProp->setAccessible(true);
    $httpProp->setValue($this->transport, null);
});

// --- Teardown ---
afterEach(function () {
    $reflector = new \ReflectionClass($this->transport);
    $closingProp = $reflector->getProperty('closing');
    $closingProp->setAccessible(true);
    if (! $closingProp->getValue($this->transport)) {
        $this->transport->close();
    }
    Mockery::close();
});

function createMockRequest(
    string $method,
    string $path,
    array $queryParams = [],
    string $bodyContent = ''
): MockInterface&ServerRequestInterface {

    $uriMock = Mockery::mock(UriInterface::class);

    $currentPath = $path;
    $currentQuery = http_build_query($queryParams);

    $uriMock->shouldReceive('getPath')->andReturnUsing(function () use (&$currentPath) {
        return $currentPath;
    })->byDefault();

    $uriMock->shouldReceive('getQuery')->andReturnUsing(function () use (&$currentQuery) {
        return $currentQuery;
    })->byDefault();

    $uriMock->shouldReceive('withPath')->andReturnUsing(
        function (string $newPath) use (&$currentPath, $uriMock) {
            $currentPath = $newPath;

            return $uriMock;
        }
    );

    $uriMock->shouldReceive('withQuery')->andReturnUsing(
        function (string $newQuery) use (&$currentQuery, $uriMock) {
            $currentQuery = $newQuery;

            return $uriMock;
        }
    );

    $uriMock->shouldReceive('withFragment')->andReturnSelf()->byDefault();
    $uriMock->shouldReceive('__toString')->andReturnUsing(
        function () use (&$currentPath, &$currentQuery) {
            return BASE_URL.$currentPath.($currentQuery ? '?'.$currentQuery : '');
        }
    )->byDefault();

    // Mock Request object
    $requestMock = Mockery::mock(ServerRequestInterface::class);
    $requestMock->shouldReceive('getMethod')->andReturn($method);
    $requestMock->shouldReceive('getUri')->andReturn($uriMock);
    $requestMock->shouldReceive('getQueryParams')->andReturn($queryParams);
    $requestMock->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json')->byDefault();
    $requestMock->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('PHPUnit Test')->byDefault();
    $requestMock->shouldReceive('getServerParams')->withNoArgs()->andReturn(['REMOTE_ADDR' => '127.0.0.1'])->byDefault();

    // Use BufferedBody for PSR-7 compatibility
    $bodyStream = new BufferedBody($bodyContent);
    $requestMock->shouldReceive('getBody')->withNoArgs()->andReturn($bodyStream)->byDefault();

    return $requestMock;
}

// --- Tests ---

test('implements correct interfaces', function () {
    expect($this->transport)
        ->toBeInstanceOf(ServerTransportInterface::class)
        ->toBeInstanceOf(LoggerAwareInterface::class)
        ->toBeInstanceOf(LoopAwareInterface::class);
});

test('request handler returns 404 for unknown paths', function () {
    $request = createMockRequest('GET', '/unknown/path');
    $response = ($this->requestHandler)($request);

    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(404);
});

// --- SSE Request Handling ---
test('handler handles GET SSE request, emits connected, returns stream response', function () {
    $request = createMockRequest('GET', SSE_PATH);
    $connectedClientId = null;
    $this->transport->on('client_connected', function ($id) use (&$connectedClientId) {
        $connectedClientId = $id;
    });

    // Act
    $response = ($this->requestHandler)($request);

    // Assert Response
    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(200);
    expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');
    $body = $response->getBody();
    expect($body)->toBeInstanceOf(ReadableStreamInterface::class);

    // Assert internal state
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streams = $streamsProp->getValue($this->transport);
    expect($streams)->toBeArray()->toHaveCount(1);
    $actualClientId = array_key_first($streams);
    expect($actualClientId)->toBeString()->toStartWith('sse_');
    expect($streams[$actualClientId])->toBeInstanceOf(ReadableStreamInterface::class);

    // Assert event emission and initial SSE event send (needs loop tick)
    $endpointSent = false;
    $streams[$actualClientId]->on('data', function ($chunk) use (&$endpointSent, $actualClientId) {
        if (str_contains($chunk, 'event: endpoint') && str_contains($chunk, "clientId={$actualClientId}")) {
            $endpointSent = true;
        }
    });

    $this->loop->addTimer(0.1, fn () => $this->loop->stop());
    $this->loop->run();

    expect($connectedClientId)->toBe($actualClientId);
    expect($endpointSent)->toBeTrue();

})->group('usesLoop');

test('handler cleans up SSE resources on stream close', function () {
    $request = createMockRequest('GET', SSE_PATH);

    $disconnectedClientId = null;
    $this->transport->on('client_disconnected', function ($id) use (&$disconnectedClientId) {
        $disconnectedClientId = $id;
    });

    // Act
    $response = ($this->requestHandler)($request);
    /** @var ThroughStream $sseStream */
    $sseStream = $response->getBody();

    // Get client ID
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $clientId = array_key_first($streamsProp->getValue($this->transport));
    expect($clientId)->toBeString(); // Ensure client ID exists

    // Simulate stream closing
    $this->loop->addTimer(0.01, fn () => $sseStream->close());
    $this->loop->addTimer(0.02, fn () => $this->loop->stop());
    $this->loop->run();

    // Assert
    expect($disconnectedClientId)->toBe($clientId);
    expect($streamsProp->getValue($this->transport))->toBeEmpty();

})->group('usesLoop');

// --- POST Request Handling ---
test('handler handles POST message, emits message, returns 202', function () {
    $clientId = 'sse_client_for_post_ok';
    $messagePayload = '{"jsonrpc":"2.0","method":"test"}';

    $mockSseStream = new ThroughStream();
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, [$clientId => $mockSseStream]);

    $request = createMockRequest('POST', MSG_PATH, ['clientId' => $clientId], $messagePayload);

    $emittedMessage = null;
    $emittedClientId = null;
    $this->transport->on('message', function ($msg, $id) use (&$emittedMessage, &$emittedClientId) {
        $emittedMessage = $msg;
        $emittedClientId = $id;
    });

    // Act
    $response = ($this->requestHandler)($request);

    // Assert
    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(202);
    expect($emittedMessage)->toBe($messagePayload);
    expect($emittedClientId)->toBe($clientId);

})->group('usesLoop');

test('handler returns 400 for POST with missing clientId', function () {
    $request = createMockRequest('POST', MSG_PATH);
    $response = ($this->requestHandler)($request); // Call handler directly
    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(400);
    // Reading body requires async handling if it's a real stream
    // expect($response->getBody()->getContents())->toContain('Missing or invalid clientId');
});

test('handler returns 404 for POST with unknown clientId', function () {
    $request = createMockRequest('POST', MSG_PATH, ['clientId' => 'unknown']);
    $response = ($this->requestHandler)($request);
    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(404);
});

test('handler returns 415 for POST with wrong Content-Type', function () {
    $clientId = 'sse_client_wrong_ct';
    $mockSseStream = new ThroughStream(); // Simulate client connected
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, [$clientId => $mockSseStream]);

    $request = createMockRequest('POST', MSG_PATH, ['clientId' => $clientId]);
    $request->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('text/plain');

    $response = ($this->requestHandler)($request);
    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(415);
});

test('handler returns 400 for POST with empty body', function () {
    $clientId = 'sse_client_empty_body';
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, [$clientId => new ThroughStream()]);

    $request = createMockRequest('POST', MSG_PATH, ['clientId' => $clientId]);

    // Act
    $response = ($this->requestHandler)($request);

    // Assert
    expect($response)->toBeInstanceOf(Response::class)->getStatusCode()->toBe(400);
    expect($response->getBody()->getContents())->toContain('Empty request body');
})->group('usesLoop');

// --- sendToClientAsync Tests ---

test('sendToClientAsync() writes SSE event correctly', function () {
    $clientId = 'sse_send_test';
    $messageJson = '{"id":99,"result":"ok"}';
    $expectedSseFrame = "event: message\ndata: {\"id\":99,\"result\":\"ok\"}\n\n";

    $sseStream = new ThroughStream(); // Use ThroughStream for testing
    $receivedData = '';
    $sseStream->on('data', function ($chunk) use (&$receivedData) {
        $receivedData .= $chunk;
    });

    // Inject the stream
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, [$clientId => $sseStream]);

    // Act
    $promise = $this->transport->sendToClientAsync($clientId, $messageJson."\n");

    // Assert
    await($promise); // Wait for promise (write is synchronous on ThroughStream if buffer allows)
    expect($receivedData)->toBe($expectedSseFrame);

})->group('usesLoop');

test('sendToClientAsync() rejects if client not found', function () {
    $promise = $this->transport->sendToClientAsync('non_existent_sse', '{}');
    $rejected = false;
    $promise->catch(function (TransportException $e) use (&$rejected) {
        expect($e->getMessage())->toContain('Client \'non_existent_sse\' not connected');
        $rejected = true;
    });
    // Need await or loop->run() if the rejection isn't immediate
    await($promise); // Await handles loop
    expect($rejected)->toBeTrue(); // Assert rejection happened
})->throws(TransportException::class); // Also assert exception type

test('sendToClientAsync() rejects if stream not writable', function () {
    $clientId = 'sse_closed_stream';
    $sseStream = new ThroughStream();
    $reflector = new \ReflectionClass($this->transport);
    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, [$clientId => $sseStream]);
    $sseStream->close(); // Close the stream

    $promise = $this->transport->sendToClientAsync($clientId, '{}');
    $rejected = false;
    $promise->catch(function (TransportException $e) use (&$rejected) {
        expect($e->getMessage())->toContain('not writable');
        $rejected = true;
    });
    await($promise); // Await handles loop
    expect($rejected)->toBeTrue(); // Assert rejection happened
})->throws(TransportException::class);

// --- close() Test ---

test('close() closes active streams and sets state', function () {
    $sseStream1 = new ThroughStream();
    $sseStream2 = new ThroughStream();
    $s1Closed = false;
    $s2Closed = false;

    $sseStream1->on('close', function () use (&$s1Closed) {
        $s1Closed = true;
    });
    $sseStream2->on('close', function () use (&$s2Closed) {
        $s2Closed = true;
    });

    // Inject state, set socket to null as we are not mocking it
    $reflector = new \ReflectionClass($this->transport);

    $socketProp = $reflector->getProperty('socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($this->transport, null);

    $httpProp = $reflector->getProperty('http');
    $httpProp->setAccessible(true);
    $httpProp->setValue($this->transport, null);

    $streamsProp = $reflector->getProperty('activeSseStreams');
    $streamsProp->setAccessible(true);
    $streamsProp->setValue($this->transport, ['c1' => $sseStream1, 'c2' => $sseStream2]);

    $listeningProp = $reflector->getProperty('listening');
    $listeningProp->setAccessible(true);
    $listeningProp->setValue($this->transport, true);

    $closeEmitted = false;
    $this->transport->on('close', function () use (&$closeEmitted) {
        $closeEmitted = true;
    });

    // Act
    $this->transport->close();

    // Assert
    expect($closeEmitted)->toBeTrue();
    expect($socketProp->getValue($this->transport))->toBeNull();
    expect($streamsProp->getValue($this->transport))->toBeEmpty();
    $closingProp = $reflector->getProperty('closing');
    $closingProp->setAccessible(true);
    expect($closingProp->getValue($this->transport))->toBeTrue();
    expect($listeningProp->getValue($this->transport))->toBeFalse();
    expect($s1Closed)->toBeTrue();
    expect($s2Closed)->toBeTrue();

})->group('usesLoop');
