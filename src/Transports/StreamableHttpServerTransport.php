<?php

declare(strict_types=1);

namespace PhpMcp\Server\Transports;

use Evenement\EventEmitterTrait;
use PhpMcp\Server\Contracts\EventStoreInterface;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Contracts\SessionIdGeneratorInterface;
use PhpMcp\Server\Defaults\DefaultUuidSessionIdGenerator;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Server\JsonRpc\Messages\Message as JsonRpcMessage;
use PhpMcp\Server\JsonRpc\Messages\BatchRequest;
use PhpMcp\Server\JsonRpc\Messages\BatchResponse;
use PhpMcp\Server\JsonRpc\Messages\Error as JsonRpcError;
use PhpMcp\Server\JsonRpc\Messages\Request as JsonRpcRequest;
use PhpMcp\Server\JsonRpc\Messages\Response as JsonRpcResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use Throwable;

use function React\Promise\resolve;
use function React\Promise\reject;

class StreamableHttpServerTransport implements ServerTransportInterface, LoggerAwareInterface, LoopAwareInterface
{
    use EventEmitterTrait;

    protected LoggerInterface $logger;
    protected LoopInterface $loop;

    private ?SocketServer $socket = null;
    private ?HttpServer $http = null;
    private bool $listening = false;
    private bool $closing = false;

    private SessionIdGeneratorInterface $sessionIdGenerator;
    private ?EventStoreInterface $eventStore;

    /**
     * Stores Deferred objects for POST requests awaiting a direct JSON response.
     * Keyed by a unique pendingRequestId.
     * @var array<string, Deferred>
     */
    private array $pendingDirectPostResponses = [];

    /**
     * Stores context for active SSE streams initiated by a POST request.
     * Helps manage when to close these streams.
     * Key: streamId
     * Value: ['expectedResponses' => int, 'receivedResponses' => int]
     * @var array<string, array>
     */
    private array $postSseStreamContexts = [];

    /**
     * Stores active SSE streams.
     * Key: streamId
     * Value: ['stream' => ThroughStream, 'sessionId' => string, 'type' => 'get' | 'post'
     * 'post_init' for SSE stream established for an InitializeRequest
     * 'post_data' for SSE stream established for other data requests
     * @var array<string, array{stream: ThroughStream, sessionId: string, type: string}>
     */
    private array $activeSseStreams = [];

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        private string $mcpPath = '/mcp',
        private ?array $sslContext = null,
        ?SessionIdGeneratorInterface $sessionIdGenerator = null,
        private readonly bool $preferDirectJsonResponse = true,
        ?EventStoreInterface $eventStore = null
    ) {
        $this->logger = new NullLogger();
        $this->loop = Loop::get();
        $this->mcpPath = '/' . trim($mcpPath, '/');
        $this->sessionIdGenerator = $sessionIdGenerator ?? new DefaultUuidSessionIdGenerator();
        $this->eventStore = $eventStore;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    private function generateStreamId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function listen(): void
    {
        if ($this->listening) {
            throw new TransportException('StreamableHttp transport is already listening.');
        }

        if ($this->closing) {
            throw new TransportException('Cannot listen, transport is closing/closed.');
        }

        $listenAddress = "{$this->host}:{$this->port}";
        $protocol = $this->sslContext ? 'https' : 'http';

        try {
            $this->socket = new SocketServer(
                $listenAddress,
                $this->sslContext ?? [],
                $this->loop
            );

            $this->http = new HttpServer($this->loop, $this->createRequestHandler());
            $this->http->listen($this->socket);

            $this->socket->on('error', function (Throwable $error) {
                $this->logger->error('Socket server error (StreamableHttp).', ['error' => $error->getMessage()]);
                $this->emit('error', [new TransportException("Socket server error: {$error->getMessage()}", 0, $error)]);
                $this->close();
            });

            $this->logger->info("Server is up and listening on {$protocol}://{$listenAddress} 🚀");
            $this->logger->info("MCP Endpoint: {$protocol}://{$listenAddress}{$this->mcpPath}");

            $this->listening = true;
            $this->closing = false;
            $this->emit('ready');
        } catch (Throwable $e) {
            $this->logger->error("Failed to start StreamableHttp listener on {$listenAddress}", ['exception' => $e]);
            throw new TransportException("Failed to start StreamableHttp listener on {$listenAddress}: {$e->getMessage()}", 0, $e);
        }
    }

    private function createRequestHandler(): callable
    {
        return function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();
            $method = $request->getMethod();

            $this->logger->debug("Request received", ['method' => $method, 'path' => $path, 'target' => $this->mcpPath]);

            if ($path !== $this->mcpPath) {
                return new HttpResponse(404, ['Content-Type' => 'text/plain'], 'Not Found');
            }

            $corsHeaders = [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Last-Event-ID, Authorization',
            ];

            if ($method === 'OPTIONS') {
                return new HttpResponse(204, $corsHeaders);
            }

            $addCors = function (HttpResponse $r) use ($corsHeaders) {
                foreach ($corsHeaders as $key => $value) {
                    $r = $r->withAddedHeader($key, $value);
                }
                return $r;
            };

            try {
                return match ($method) {
                    'GET' => $this->handleGetRequest($request)->then($addCors, fn($e) => $addCors($this->handleRequestError($e, $request))),
                    'POST' => $this->handlePostRequest($request)->then($addCors, fn($e) => $addCors($this->handleRequestError($e, $request))),
                    'DELETE' => $this->handleDeleteRequest($request)->then($addCors, fn($e) => $addCors($this->handleRequestError($e, $request))),
                    default => $addCors(new HttpResponse(405, ['Content-Type' => 'text/plain', 'Allow' => 'GET, POST, DELETE, OPTIONS'], 'Method Not Allowed')),
                };
            } catch (Throwable $e) {
                return $addCors($this->handleRequestError($e, $request));
            }
        };
    }

    private function handleGetRequest(ServerRequestInterface $request): PromiseInterface
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        if (!str_contains($acceptHeader, 'text/event-stream')) {
            return resolve(new HttpResponse(406, ['Content-Type' => 'text/plain'], 'Not Acceptable: Client must accept text/event-stream for GET requests.'));
        }

        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if (empty($sessionId)) {
            $this->logger->warning("GET request without Mcp-Session-Id.");
            $error = JsonRpcError::invalidRequest("Mcp-Session-Id header required for GET requests.");
            return resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
        }

        $streamId = $this->generateStreamId();
        $sseStream = new ThroughStream();

        $this->activeSseStreams[$streamId] = ['stream' => $sseStream, 'sessionId' => $sessionId, 'type' => 'get'];

        $sseStream->on('close', function () use ($streamId, $sessionId) {
            $this->logger->info("StreamableHttp: GET SSE stream closed.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
            unset($this->activeSseStreams[$streamId]);
        });

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        $response = new HttpResponse(200, $headers, $sseStream);

        if ($this->eventStore) {
            $lastEventId = $request->getHeaderLine('Last-Event-ID');
            if (!empty($lastEventId)) {
                try {
                    $this->eventStore->replayEventsAfter(
                        $lastEventId,
                        function (string $replayedEventId, string $json) use ($sseStream, $streamId) {
                            $this->logger->debug("Replaying event", ['targetstreamId' => $streamId, 'replayedEventId' => $replayedEventId]);
                            $this->sendSseEventToStream($sseStream, $json, $replayedEventId);
                        }
                    );
                } catch (Throwable $e) {
                    $this->logger->error("Error during event replay.", ['streamId' => $streamId, 'sessionId' => $sessionId, 'exception' => $e]);
                }
            }
        }

        $this->emit('client_connected', [$sessionId, $streamId]);

        return resolve($response);
    }

    private function handlePostRequest(ServerRequestInterface $request): PromiseInterface
    {
        $deferred = new Deferred();

        $acceptHeader = $request->getHeaderLine('Accept');
        if (!str_contains($acceptHeader, 'application/json') && !str_contains($acceptHeader, 'text/event-stream')) {
            $deferred->resolve(new HttpResponse(406, ['Content-Type' => 'text/plain'], 'Not Acceptable: Client must accept application/json or text/event-stream'));
            return $deferred->promise();
        }

        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $deferred->resolve(new HttpResponse(415, ['Content-Type' => 'text/plain'], 'Unsupported Media Type: Content-Type must be application/json'));
            return $deferred->promise();
        }

        $bodyContents = $request->getBody()->getContents();

        if ($bodyContents === '') {
            $this->logger->warning("Received empty POST body");
            $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'text/plain'], 'Empty request body.'));
            return $deferred->promise();
        }

        try {
            $message = JsonRpcMessage::parseRequest($bodyContents);
        } catch (Throwable $e) {
            $this->logger->error("Failed to parse MCP message from POST body", ['error' => $e->getMessage()]);
            $error = JsonRpcError::parseError("Invalid JSON: " . $e->getMessage());
            $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
            return $deferred->promise();
        }

        $isInitializeRequest = ($message instanceof JsonRpcRequest && $message->method === 'initialize');
        $sessionId = null;
        if ($isInitializeRequest) {
            if ($request->hasHeader('Mcp-Session-Id')) {
                $this->logger->warning("Client sent Mcp-Session-Id with InitializeRequest. Ignoring.", ['clientSentId' => $request->getHeaderLine('Mcp-Session-Id')]);
            }
            $sessionId = $this->sessionIdGenerator->generateId();
        } else {
            $sessionId = $request->getHeaderLine('Mcp-Session-Id');

            if (empty($sessionId)) {
                $this->logger->warning("POST request without Mcp-Session-Id.");
                $error = JsonRpcError::invalidRequest("Mcp-Session-Id header required for POST requests.");
                $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
                return $deferred->promise();
            }
        }

        $context = [
            'is_initialize_request' => $isInitializeRequest,
        ];

        $hasRequests = false;
        $nRequests = 0;
        if ($message instanceof JsonRpcRequest) {
            $hasRequests = true;
            $nRequests = 1;
        } elseif ($message instanceof BatchRequest) {
            $hasRequests = $message->hasRequests();
            $nRequests = count($message->getRequests());
        }

        if (!$hasRequests) {
            $deferred->resolve(new HttpResponse(202));
            $context['type'] = 'post_202_sent';
        } else {
            $clientPrefersSse = str_contains($acceptHeader, 'text/event-stream');
            $clientAcceptsJson = str_contains($acceptHeader, 'application/json');
            $useSse = $clientPrefersSse && !($this->preferDirectJsonResponse && $clientAcceptsJson);

            if ($useSse) {
                $streamId = $this->generateStreamId();
                $sseStream = new ThroughStream();
                $this->activeSseStreams[$streamId] = ['stream' => $sseStream, 'sessionId' => $sessionId, 'type' => 'post'];
                $this->postSseStreamContexts[$streamId] = [
                    'nRequests' => $nRequests,
                    'nResponses' => 0,
                    'sessionId' => $sessionId
                ];

                $sseStream->on('close', function () use ($streamId) {
                    $this->logger->info("POST SSE stream closed by client/server.", ['streamId' => $streamId, 'sessionId' => $this->postSseStreamContexts[$streamId]['sessionId'] ?? 'unknown']);
                    unset($this->activeSseStreams[$streamId]);
                    unset($this->postSseStreamContexts[$streamId]);
                });
                $sseStream->on('error', function (Throwable $e) use ($streamId) {
                    $this->logger->error("POST SSE stream error.", ['streamId' => $streamId, 'sessionId' => $this->postSseStreamContexts[$streamId]['sessionId'] ?? 'unknown', 'error' => $e->getMessage()]);
                    unset($this->activeSseStreams[$streamId]);
                    unset($this->postSseStreamContexts[$streamId]);
                });

                $headers = [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'Connection' => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ];

                if ($this->sessionIdGenerator !== null && $sessionId && $message->method !== 'initialize') {
                    if ($request->hasHeader('Mcp-Session-Id')) {
                        $headers['Mcp-Session-Id'] = $sessionId;
                    }
                }

                $deferred->resolve(new HttpResponse(200, $headers, $sseStream));
                $context['type'] = 'post_sse';
                $context['streamId'] = $streamId;
                $context['nRequests'] = $nRequests;
            } else {
                $pendingRequestId = $this->generateStreamId();
                $this->pendingDirectPostResponses[$pendingRequestId] = $deferred;

                $timeoutTimer = $this->loop->addTimer(30, function () use ($pendingRequestId, $sessionId) {
                    if (isset($this->pendingDirectPostResponses[$pendingRequestId])) {
                        $deferred = $this->pendingDirectPostResponses[$pendingRequestId];
                        unset($this->pendingDirectPostResponses[$pendingRequestId]);
                        $this->logger->warning("Timeout waiting for direct JSON response processing.", ['pending_request_id' => $pendingRequestId, 'session_id' => $sessionId]);
                        $errorResponse = McpServerException::internalError("Request processing timed out.")->toJsonRpcError($pendingRequestId);
                        $deferred->resolve(new HttpResponse(500, ['Content-Type' => 'application/json'], json_encode($errorResponse->toArray())));
                    }
                });

                $this->pendingDirectPostResponses[$pendingRequestId]->promise()->finally(function () use ($timeoutTimer) {
                    $this->loop->cancelTimer($timeoutTimer);
                });

                $context['type'] = 'post_json';
                $context['pending_request_id'] = $pendingRequestId;
            }
        }

        $this->emit('message', [$message, $sessionId, $context]);

        return $deferred->promise();
    }

    private function handleDeleteRequest(ServerRequestInterface $request): PromiseInterface
    {
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if (empty($sessionId)) {
            $this->logger->warning("DELETE request without Mcp-Session-Id.");
            $error = JsonRpcError::invalidRequest("Mcp-Session-Id header required for DELETE.");
            return resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
        }

        // TODO: Use session manager to handle this?

        // TODO: Close all associated HTTP streams for this session

        // TODO: Clean up session tracking in this transport

        // TODO: Remove any mappings for requests belonging to this session

        foreach ($this->activeSseStreams as $streamId => $streamInfo) {
            if ($streamInfo['sessionId'] === $sessionId) {
                $streamInfo['stream']->end();
            }
        }

        $this->emit('client_disconnected', [$sessionId, null, 'Session terminated by DELETE request']); // No specific streamId, signals whole session.

        return resolve(new HttpResponse(204));
    }

    private function handleRequestError(Throwable $e, ServerRequestInterface $request): HttpResponse
    {
        $this->logger->error("Error processing HTTP request", [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'exception' => $e
        ]);

        if ($e instanceof TransportException) {
            return new HttpResponse(500, ['Content-Type' => 'text/plain'], 'Transport Error: ' . $e->getMessage());
        }

        return new HttpResponse(500, ['Content-Type' => 'text/plain'], 'Internal Server Error during HTTP request processing.');
    }

    public function sendMessage(JsonRpcMessage|null $message, string $sessionId, array $context = []): PromiseInterface
    {
        if ($this->closing) {
            return reject(new TransportException('Transport is closing.'));
        }

        $isInitializeResponse = ($context['is_initialize_request'] ?? false) && ($message instanceof JsonRpcResponse);

        switch ($context['type'] ?? null) {
            case 'post_202_sent':
                return resolve(null);

            case 'post_sse':
                $streamId = $context['streamId'];
                if (!isset($this->activeSseStreams[$streamId])) {
                    $this->logger->error("SSE stream for POST not found.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
                    return reject(new TransportException("SSE stream {$streamId} not found for POST response."));
                }

                $stream = $this->activeSseStreams[$streamId]['stream'];
                if (!$stream->isWritable()) {
                    $this->logger->warning("SSE stream for POST is not writable.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
                    return reject(new TransportException("SSE stream {$streamId} for POST is not writable."));
                }

                $sentCountThisCall = 0;

                if ($message instanceof JsonRpcResponse || $message instanceof JsonRpcError) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $eventId = $this->eventStore ? $this->eventStore->storeEvent($streamId, $json) : null;
                    $this->sendSseEventToStream($stream, $json, $eventId);
                    $sentCountThisCall = 1;
                } elseif ($message instanceof BatchResponse) {
                    foreach ($message->all() as $singleResponse) {
                        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $eventId = $this->eventStore ? $this->eventStore->storeEvent($streamId, $json) : null;
                        $this->sendSseEventToStream($stream, $json, $eventId);
                        $sentCountThisCall++;
                    }
                }

                if (isset($this->postSseStreamContexts[$streamId])) {
                    $this->postSseStreamContexts[$streamId]['nResponses'] += $sentCountThisCall;
                    $sCtx = $this->postSseStreamContexts[$streamId];
                    if ($sCtx['nResponses'] >= $sCtx['nRequests']) {
                        $this->logger->info("All expected responses sent for POST SSE stream. Closing.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
                        $stream->end(); // Will trigger 'close' event.
                    }
                }
                return resolve(null);

            case 'post_json':
                $pendingRequestId = $context['pending_request_id'];
                if (!isset($this->pendingDirectPostResponses[$pendingRequestId])) {
                    $this->logger->error("Pending direct JSON request not found.", ['pending_request_id' => $pendingRequestId, 'session_id' => $sessionId]);
                    return reject(new TransportException("Pending request {$pendingRequestId} not found."));
                }

                $deferred = $this->pendingDirectPostResponses[$pendingRequestId];
                unset($this->pendingDirectPostResponses[$pendingRequestId]);

                $responseBody = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $headers = ['Content-Type' => 'application/json'];
                if ($isInitializeResponse) {
                    $headers['Mcp-Session-Id'] = $sessionId;
                }

                $deferred->resolve(new HttpResponse(200, $headers, $responseBody));
                return resolve(null);

            case 'get_sse':
                $streamId = $context['streamId'];
                if (!isset($this->activeSseStreams[$streamId])) {
                    $this->logger->error("GET SSE stream not found.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
                    return reject(new TransportException("GET SSE stream {$streamId} not found."));
                }

                $stream = $this->activeSseStreams[$streamId]['stream'];
                if (!$stream->isWritable()) {
                    $this->logger->warning("GET SSE stream is not writable.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
                    return reject(new TransportException("GET SSE stream {$streamId} not writable."));
                }
                if ($message instanceof JsonRpcResponse || $message instanceof JsonRpcError) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $eventId = $this->eventStore ? $this->eventStore->storeEvent($streamId, $json) : null;
                    $this->sendSseEventToStream($stream, $json, $eventId);
                } elseif ($message instanceof BatchResponse) {
                    foreach ($message->all() as $singleResponse) {
                        $json = json_encode($singleResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $eventId = $this->eventStore ? $this->eventStore->storeEvent($streamId, $json) : null;
                        $this->sendSseEventToStream($stream, $json, $eventId);
                    }
                }
                return resolve(null);

            default:
                $this->logger->error("Unknown sendMessage context type.", ['context' => $context, 'sessionId' => $sessionId]);
                return reject(new TransportException("Unknown sendMessage context type: " . ($context['type'] ?? 'null')));
        }
    }

    private function sendSseEventToStream(ThroughStream $stream, string $data, ?string $eventId = null): bool
    {
        if (! $stream->isWritable()) {
            return false;
        }

        $frame = "event: message\n";
        if ($eventId !== null) {
            $frame .= "id: {$eventId}\n";
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $frame .= "data: {$line}\n";
        }
        $frame .= "\n";

        return $stream->write($frame);
    }

    public function close(): void
    {
        if ($this->closing) {
            return;
        }

        $this->closing = true;
        $this->listening = false;
        $this->logger->info('Closing transport...');

        if ($this->socket) {
            $this->socket->close();
            $this->socket = null;
        }

        foreach ($this->activeSseStreams as $streamId => $streamInfo) {
            if ($streamInfo['stream']->isWritable()) {
                $streamInfo['stream']->end();
            }
        }

        foreach ($this->pendingDirectPostResponses as $pendingRequestId => $deferred) {
            $deferred->reject(new TransportException('Transport is closing.'));
        }

        $this->activeSseStreams = [];
        $this->postSseStreamContexts = [];
        $this->pendingDirectPostResponses = [];

        $this->emit('close', ['Transport closed.']);
        $this->removeAllListeners();
    }
}
