<?php

declare(strict_types=1);

namespace PhpMcp\Server\Transports;

use Evenement\EventEmitterTrait;
use PhpMcp\Server\Contracts\EventStoreInterface;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Schema\JsonRpc\Message;
use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Schema\JsonRpc\BatchResponse;
use PhpMcp\Schema\JsonRpc\Error;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Response;
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

    private ?EventStoreInterface $eventStore;

    /**
     * Stores Deferred objects for POST requests awaiting a direct JSON response.
     * Keyed by a unique pendingRequestId.
     * @var array<string, Deferred>
     */
    private array $pendingRequests = [];

    /**
     * Stores active SSE streams.
     * Key: streamId
     * Value: ['stream' => ThroughStream, 'sessionId' => string, 'context' => array]
     * @var array<string, array{stream: ThroughStream, sessionId: string, context: array}>
     */
    private array $activeSseStreams = [];

    private ?ThroughStream $getStream = null;

    /**
     * @param bool $enableJsonResponse If true, the server will return JSON responses instead of starting an SSE stream.
     * This can be useful for simple request/response scenarios without streaming.
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        private string $mcpPath = '/mcp',
        private ?array $sslContext = null,
        private readonly bool $enableJsonResponse = true,
        private readonly bool $stateless = false,
        ?EventStoreInterface $eventStore = null
    ) {
        $this->logger = new NullLogger();
        $this->loop = Loop::get();
        $this->mcpPath = '/' . trim($mcpPath, '/');
        $this->eventStore = $eventStore;
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex characters
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
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
                $error = Error::forInvalidRequest("Not found: {$path}");
                return new HttpResponse(404, ['Content-Type' => 'application/json'], json_encode($error));
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
                    default => $addCors($this->handleUnsupportedRequest($request)),
                };
            } catch (Throwable $e) {
                return $addCors($this->handleRequestError($e, $request));
            }
        };
    }

    private function handleGetRequest(ServerRequestInterface $request): PromiseInterface
    {
        if ($this->stateless) {
            $error = Error::forInvalidRequest("GET requests (SSE streaming) are not supported in stateless mode.");
            return resolve(new HttpResponse(405, ['Content-Type' => 'application/json'], json_encode($error)));
        }

        $acceptHeader = $request->getHeaderLine('Accept');
        if (!str_contains($acceptHeader, 'text/event-stream')) {
            $error = Error::forInvalidRequest("Not Acceptable: Client must accept text/event-stream for GET requests.");
            return resolve(new HttpResponse(406, ['Content-Type' => 'application/json'], json_encode($error)));
        }

        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if (empty($sessionId)) {
            $this->logger->warning("GET request without Mcp-Session-Id.");
            $error = Error::forInvalidRequest("Mcp-Session-Id header required for GET requests.");
            return resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
        }

        $this->getStream = new ThroughStream();

        $this->getStream->on('close', function () use ($sessionId) {
            $this->logger->debug("GET SSE stream closed.", ['sessionId' => $sessionId]);
            $this->getStream = null;
        });

        $this->getStream->on('error', function (Throwable $e) use ($sessionId) {
            $this->logger->error("GET SSE stream error.", ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
            $this->getStream = null;
        });

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        $response = new HttpResponse(200, $headers, $this->getStream);

        if ($this->eventStore) {
            $lastEventId = $request->getHeaderLine('Last-Event-ID');
            $this->replayEvents($lastEventId, $this->getStream, $sessionId);
        }

        return resolve($response);
    }

    private function handlePostRequest(ServerRequestInterface $request): PromiseInterface
    {
        $deferred = new Deferred();

        $acceptHeader = $request->getHeaderLine('Accept');
        if (!str_contains($acceptHeader, 'application/json') && !str_contains($acceptHeader, 'text/event-stream')) {
            $error = Error::forInvalidRequest("Not Acceptable: Client must accept both application/json or text/event-stream");
            $deferred->resolve(new HttpResponse(406, ['Content-Type' => 'application/json'], json_encode($error)));
            return $deferred->promise();
        }

        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $error = Error::forInvalidRequest("Unsupported Media Type: Content-Type must be application/json");
            $deferred->resolve(new HttpResponse(415, ['Content-Type' => 'application/json'], json_encode($error)));
            return $deferred->promise();
        }

        $body = $request->getBody()->getContents();

        if (empty($body)) {
            $this->logger->warning("Received empty POST body");
            $error = Error::forInvalidRequest("Empty request body.");
            $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
            return $deferred->promise();
        }

        try {
            $message = Parser::parse($body);
        } catch (Throwable $e) {
            $this->logger->error("Failed to parse MCP message from POST body", ['error' => $e->getMessage()]);
            $error = Error::forParseError("Invalid JSON: " . $e->getMessage());
            $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
            return $deferred->promise();
        }

        $isInitializeRequest = ($message instanceof Request && $message->method === 'initialize');
        $sessionId = null;

        if ($this->stateless) {
            $sessionId = $this->generateId();
            $this->emit('client_connected', [$sessionId]);
        } else {
            if ($isInitializeRequest) {
                if ($request->hasHeader('Mcp-Session-Id')) {
                    $this->logger->warning("Client sent Mcp-Session-Id with InitializeRequest. Ignoring.", ['clientSentId' => $request->getHeaderLine('Mcp-Session-Id')]);
                    $error = Error::forInvalidRequest("Invalid request: Session already initialized. Mcp-Session-Id header not allowed with InitializeRequest.", $message->getId());
                    $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
                    return $deferred->promise();
                }

                $sessionId = $this->generateId();
                $this->emit('client_connected', [$sessionId]);
            } else {
                $sessionId = $request->getHeaderLine('Mcp-Session-Id');

                if (empty($sessionId)) {
                    $this->logger->warning("POST request without Mcp-Session-Id.");
                    $error = Error::forInvalidRequest("Mcp-Session-Id header required for POST requests.", $message->getId());
                    $deferred->resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
                    return $deferred->promise();
                }
            }
        }

        $context = [
            'is_initialize_request' => $isInitializeRequest,
        ];

        $nRequests = match (true) {
            $message instanceof Request => 1,
            $message instanceof BatchRequest => $message->nRequests(),
            default => 0,
        };

        if ($nRequests === 0) {
            $deferred->resolve(new HttpResponse(202));
            $context['type'] = 'post_202_sent';
        } else {
            if ($this->enableJsonResponse) {
                $pendingRequestId = $this->generateId();
                $this->pendingRequests[$pendingRequestId] = $deferred;

                $timeoutTimer = $this->loop->addTimer(30, function () use ($pendingRequestId, $sessionId) {
                    if (isset($this->pendingRequests[$pendingRequestId])) {
                        $deferred = $this->pendingRequests[$pendingRequestId];
                        unset($this->pendingRequests[$pendingRequestId]);
                        $this->logger->warning("Timeout waiting for direct JSON response processing.", ['pending_request_id' => $pendingRequestId, 'session_id' => $sessionId]);
                        $errorResponse = McpServerException::internalError("Request processing timed out.")->toJsonRpcError($pendingRequestId);
                        $deferred->resolve(new HttpResponse(500, ['Content-Type' => 'application/json'], json_encode($errorResponse->toArray())));
                    }
                });

                $this->pendingRequests[$pendingRequestId]->promise()->finally(function () use ($timeoutTimer) {
                    $this->loop->cancelTimer($timeoutTimer);
                });

                $context['type'] = 'post_json';
                $context['pending_request_id'] = $pendingRequestId;
            } else {
                $streamId = $this->generateId();
                $sseStream = new ThroughStream();
                $this->activeSseStreams[$streamId] = [
                    'stream' => $sseStream,
                    'sessionId' => $sessionId,
                    'context' => ['nRequests' => $nRequests, 'nResponses' => 0]
                ];

                $sseStream->on('close', function () use ($streamId) {
                    $this->logger->info("POST SSE stream closed by client/server.", ['streamId' => $streamId, 'sessionId' => $this->activeSseStreams[$streamId]['sessionId']]);
                    unset($this->activeSseStreams[$streamId]);
                });
                $sseStream->on('error', function (Throwable $e) use ($streamId) {
                    $this->logger->error("POST SSE stream error.", ['streamId' => $streamId, 'sessionId' => $this->activeSseStreams[$streamId]['sessionId'], 'error' => $e->getMessage()]);
                    unset($this->activeSseStreams[$streamId]);
                });

                $headers = [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'Connection' => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ];

                if (!empty($sessionId) && !$this->stateless) {
                    $headers['Mcp-Session-Id'] = $sessionId;
                }

                $deferred->resolve(new HttpResponse(200, $headers, $sseStream));
                $context['type'] = 'post_sse';
                $context['streamId'] = $streamId;
                $context['nRequests'] = $nRequests;
            }
        }

        $context['stateless'] = $this->stateless;

        $this->loop->futureTick(function () use ($message, $sessionId, $context) {
            $this->emit('message', [$message, $sessionId, $context]);
        });

        return $deferred->promise();
    }

    private function handleDeleteRequest(ServerRequestInterface $request): PromiseInterface
    {
        if ($this->stateless) {
            return resolve(new HttpResponse(204));
        }

        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if (empty($sessionId)) {
            $this->logger->warning("DELETE request without Mcp-Session-Id.");
            $error = Error::forInvalidRequest("Mcp-Session-Id header required for DELETE.");
            return resolve(new HttpResponse(400, ['Content-Type' => 'application/json'], json_encode($error)));
        }

        $streamsToClose = [];
        foreach ($this->activeSseStreams as $streamId => $streamInfo) {
            if ($streamInfo['sessionId'] === $sessionId) {
                $streamsToClose[] = $streamId;
            }
        }

        foreach ($streamsToClose as $streamId) {
            $this->activeSseStreams[$streamId]['stream']->end();
            unset($this->activeSseStreams[$streamId]);
        }

        if ($this->getStream !== null) {
            $this->getStream->end();
            $this->getStream = null;
        }

        $this->emit('client_disconnected', [$sessionId, 'Session terminated by DELETE request']);

        return resolve(new HttpResponse(204));
    }

    private function handleUnsupportedRequest(ServerRequestInterface $request): HttpResponse
    {
        $error = Error::forInvalidRequest("Method not allowed: {$request->getMethod()}");
        $headers = [
            'Content-Type' => 'application/json',
            'Allow' => 'GET, POST, DELETE, OPTIONS',
        ];
        return new HttpResponse(405, $headers, json_encode($error));
    }

    private function handleRequestError(Throwable $e, ServerRequestInterface $request): HttpResponse
    {
        $this->logger->error("Error processing HTTP request", [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'exception' => $e->getMessage()
        ]);

        if ($e instanceof TransportException) {
            $error = Error::forInternalError("Transport Error: " . $e->getMessage());
            return new HttpResponse(500, ['Content-Type' => 'application/json'], json_encode($error));
        }

        $error = Error::forInternalError("Internal Server Error during HTTP request processing.");
        return new HttpResponse(500, ['Content-Type' => 'application/json'], json_encode($error));
    }

    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
    {
        if ($this->closing) {
            return reject(new TransportException('Transport is closing.'));
        }

        $isInitializeResponse = ($context['is_initialize_request'] ?? false) && ($message instanceof Response);

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

                if ($message instanceof Response || $message instanceof Error) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $eventId = $this->eventStore ? $this->eventStore->storeEvent($streamId, $json) : null;
                    $this->sendSseEventToStream($stream, $json, $eventId);
                    $sentCountThisCall = 1;
                } elseif ($message instanceof BatchResponse) {
                    foreach ($message->getAll() as $singleResponse) {
                        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $eventId = $this->eventStore ? $this->eventStore->storeEvent($streamId, $json) : null;
                        $this->sendSseEventToStream($stream, $json, $eventId);
                        $sentCountThisCall++;
                    }
                }

                if (isset($this->activeSseStreams[$streamId]['context'])) {
                    $this->activeSseStreams[$streamId]['context']['nResponses'] += $sentCountThisCall;
                    if ($this->activeSseStreams[$streamId]['context']['nResponses'] >= $this->activeSseStreams[$streamId]['context']['nRequests']) {
                        $this->logger->info("All expected responses sent for POST SSE stream. Closing.", ['streamId' => $streamId, 'sessionId' => $sessionId]);
                        $stream->end(); // Will trigger 'close' event.

                        if ($context['stateless'] ?? false) {
                            $this->loop->futureTick(function () use ($sessionId) {
                                $this->emit('client_disconnected', [$sessionId, 'Stateless request completed']);
                            });
                        }
                    }
                }

                return resolve(null);

            case 'post_json':
                $pendingRequestId = $context['pending_request_id'];
                if (!isset($this->pendingRequests[$pendingRequestId])) {
                    $this->logger->error("Pending direct JSON request not found.", ['pending_request_id' => $pendingRequestId, 'session_id' => $sessionId]);
                    return reject(new TransportException("Pending request {$pendingRequestId} not found."));
                }

                $deferred = $this->pendingRequests[$pendingRequestId];
                unset($this->pendingRequests[$pendingRequestId]);

                $responseBody = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $headers = ['Content-Type' => 'application/json'];
                if ($isInitializeResponse && !$this->stateless) {
                    $headers['Mcp-Session-Id'] = $sessionId;
                }

                $statusCode = $context['status_code'] ?? 200;
                $deferred->resolve(new HttpResponse($statusCode, $headers, $responseBody . "\n"));

                if ($context['stateless'] ?? false) {
                    $this->loop->futureTick(function () use ($sessionId) {
                        $this->emit('client_disconnected', [$sessionId, 'Stateless request completed']);
                    });
                }

                return resolve(null);

            default:
                if ($this->getStream === null) {
                    $this->logger->error("GET SSE stream not found.", ['sessionId' => $sessionId]);
                    return reject(new TransportException("GET SSE stream not found."));
                }

                if (!$this->getStream->isWritable()) {
                    $this->logger->warning("GET SSE stream is not writable.", ['sessionId' => $sessionId]);
                    return reject(new TransportException("GET SSE stream not writable."));
                }
                if ($message instanceof Response || $message instanceof Error) {
                    $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $eventId = $this->eventStore ? $this->eventStore->storeEvent('GET_STREAM', $json) : null;
                    $this->sendSseEventToStream($this->getStream, $json, $eventId);
                } elseif ($message instanceof BatchResponse) {
                    foreach ($message->getAll() as $singleResponse) {
                        $json = json_encode($singleResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $eventId = $this->eventStore ? $this->eventStore->storeEvent('GET_STREAM', $json) : null;
                        $this->sendSseEventToStream($this->getStream, $json, $eventId);
                    }
                }
                return resolve(null);
        }
    }

    private function replayEvents(string $lastEventId, ThroughStream $sseStream, string $sessionId): void
    {
        if (empty($lastEventId)) {
            return;
        }

        try {
            $this->eventStore->replayEventsAfter(
                $lastEventId,
                function (string $replayedEventId, string $json) use ($sseStream) {
                    $this->logger->debug("Replaying event", ['replayedEventId' => $replayedEventId]);
                    $this->sendSseEventToStream($sseStream, $json, $replayedEventId);
                }
            );
        } catch (Throwable $e) {
            $this->logger->error("Error during event replay.", ['sessionId' => $sessionId, 'exception' => $e]);
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

        if ($this->getStream !== null) {
            $this->getStream->end();
            $this->getStream = null;
        }

        foreach ($this->pendingRequests as $pendingRequestId => $deferred) {
            $deferred->reject(new TransportException('Transport is closing.'));
        }

        $this->activeSseStreams = [];
        $this->pendingRequests = [];

        $this->emit('close', ['Transport closed.']);
        $this->removeAllListeners();
    }
}
