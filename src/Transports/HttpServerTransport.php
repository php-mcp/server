<?php

declare(strict_types=1);

namespace PhpMcp\Server\Transports;

use Evenement\EventEmitterTrait;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\TransportException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;
use Throwable;

use function React\Promise\resolve;
use function React\Promise\reject;

/**
 * Implementation of the HTTP+SSE server transport using ReactPHP components.
 *
 * Listens for HTTP connections, manages SSE streams, and emits events.
 */
class HttpServerTransport implements LoggerAwareInterface, LoopAwareInterface, ServerTransportInterface
{
    use EventEmitterTrait;

    protected LoggerInterface $logger;

    protected LoopInterface $loop;

    protected ?SocketServer $socket = null;

    protected ?HttpServer $http = null;

    /** @var array<string, ThroughStream> clientId => SSE Stream */
    protected array $activeSseStreams = [];

    protected bool $listening = false;

    protected bool $closing = false;

    protected string $ssePath;

    protected string $messagePath;

    /**
     * @param  string  $host  Host to bind to (e.g., '127.0.0.1', '0.0.0.0').
     * @param  int  $port  Port to listen on (e.g., 8080).
     * @param  string  $mcpPathPrefix  URL prefix for MCP endpoints (e.g., 'mcp').
     * @param  array|null  $sslContext  Optional SSL context options for React SocketServer (for HTTPS).
     */
    public function __construct(
        protected readonly string $host = '127.0.0.1',
        protected readonly int $port = 8080,
        protected readonly string $mcpPathPrefix = 'mcp', // e.g., /mcp/sse, /mcp/message
        protected readonly ?array $sslContext = null // For enabling HTTPS
    ) {
        $this->logger = new NullLogger();
        $this->loop = Loop::get();
        $this->ssePath = '/' . trim($mcpPathPrefix, '/') . '/sse';
        $this->messagePath = '/' . trim($mcpPathPrefix, '/') . '/message';
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setLoop(LoopInterface $loop): void
    {
        $this->loop = $loop;
    }

    /**
     * Starts the HTTP server listener.
     *
     * @throws TransportException If port binding fails.
     */
    public function listen(): void
    {
        if ($this->listening) {
            throw new TransportException('Http transport is already listening.');
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
                $this->logger->error('Socket server error.', ['error' => $error->getMessage()]);
                $this->emit('error', [new TransportException("Socket server error: {$error->getMessage()}", 0, $error)]);
                $this->close();
            });

            $this->logger->info("Server is up and listening on {$protocol}://{$listenAddress} 🚀");
            $this->logger->info("SSE Endpoint: {$protocol}://{$listenAddress}{$this->ssePath}");
            $this->logger->info("Message Endpoint: {$protocol}://{$listenAddress}{$this->messagePath}");

            $this->listening = true;
            $this->closing = false;
            $this->emit('ready');
        } catch (Throwable $e) {
            $this->logger->error("Failed to start listener on {$listenAddress}", ['exception' => $e]);
            throw new TransportException("Failed to start HTTP listener on {$listenAddress}: {$e->getMessage()}", 0, $e);
        }
    }

    /** Creates the main request handling callback for ReactPHP HttpServer */
    protected function createRequestHandler(): callable
    {
        return function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();
            $method = $request->getMethod();
            $this->logger->debug('Received request', ['method' => $method, 'path' => $path]);

            // --- SSE Connection Handling ---
            if ($method === 'GET' && $path === $this->ssePath) {
                return $this->handleSseRequest($request);
            }

            // --- Message POST Handling ---
            if ($method === 'POST' && $path === $this->messagePath) {
                return $this->handleMessagePostRequest($request);
            }

            // --- Not Found ---
            $this->logger->debug('404 Not Found', ['method' => $method, 'path' => $path]);

            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        };
    }

    /** Handles a new SSE connection request */
    protected function handleSseRequest(ServerRequestInterface $request): Response
    {
        $clientId = 'sse_' . bin2hex(random_bytes(16));
        $this->logger->info('New SSE connection', ['clientId' => $clientId]);

        $sseStream = new ThroughStream();

        $sseStream->on('close', function () use ($clientId) {
            $this->logger->info('SSE stream closed', ['clientId' => $clientId]);
            unset($this->activeSseStreams[$clientId]);
            $this->emit('client_disconnected', [$clientId, 'SSE stream closed']);
        });

        $sseStream->on('error', function (Throwable $error) use ($clientId) {
            $this->logger->warning('SSE stream error', ['clientId' => $clientId, 'error' => $error->getMessage()]);
            unset($this->activeSseStreams[$clientId]);
            $this->emit('error', [new TransportException("SSE Stream Error: {$error->getMessage()}", 0, $error), $clientId]);
            $this->emit('client_disconnected', [$clientId, 'SSE stream error']);
        });

        $this->activeSseStreams[$clientId] = $sseStream;

        $this->loop->futureTick(function () use ($clientId, $request, $sseStream) {
            if (! isset($this->activeSseStreams[$clientId]) || ! $sseStream->isWritable()) {
                $this->logger->warning('Cannot send initial endpoint event, stream closed/invalid early.', ['clientId' => $clientId]);

                return;
            }

            try {
                $postEndpoint = $this->messagePath . "?clientId={$clientId}";
                $this->sendSseEvent($sseStream, 'endpoint', $postEndpoint, "init-{$clientId}");

                $this->emit('client_connected', [$clientId]);
            } catch (Throwable $e) {
                $this->logger->error('Error sending initial endpoint event', ['clientId' => $clientId, 'exception' => $e]);
                $sseStream->close();
            }
        });

        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
            ],
            $sseStream
        );
    }

    /** Handles incoming POST requests with messages */
    protected function handleMessagePostRequest(ServerRequestInterface $request): Response
    {
        $queryParams = $request->getQueryParams();
        $clientId = $queryParams['clientId'] ?? null;

        if (! $clientId || ! is_string($clientId)) {
            $this->logger->warning('Received POST without valid clientId query parameter.');

            return new Response(400, ['Content-Type' => 'text/plain'], 'Missing or invalid clientId query parameter');
        }

        if (! isset($this->activeSseStreams[$clientId])) {
            $this->logger->warning('Received POST for unknown or disconnected clientId.', ['clientId' => $clientId]);

            return new Response(404, ['Content-Type' => 'text/plain'], 'Client ID not found or disconnected');
        }

        if (! str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            return new Response(415, ['Content-Type' => 'text/plain'], 'Content-Type must be application/json');
        }

        $body = $request->getBody()->getContents();

        if (empty($body)) {
            $this->logger->warning('Received empty POST body', ['clientId' => $clientId]);

            return new Response(400, ['Content-Type' => 'text/plain'], 'Empty request body');
        }

        $this->emit('message', [$body, $clientId]);

        return new Response(202, ['Content-Type' => 'text/plain'], 'Accepted');
    }

    /**
     * Sends a raw JSON-RPC message frame to a specific client via SSE.
     */
    public function sendToClientAsync(string $clientId, string $rawFramedMessage): PromiseInterface
    {
        if (! isset($this->activeSseStreams[$clientId])) {
            return reject(new TransportException("Cannot send message: Client '{$clientId}' not connected via SSE."));
        }

        $stream = $this->activeSseStreams[$clientId];
        if (! $stream->isWritable()) {
            return reject(new TransportException("Cannot send message: SSE stream for client '{$clientId}' is not writable."));
        }

        $jsonData = trim($rawFramedMessage);

        if ($jsonData === '') {
            return resolve(null);
        }

        $deferred = new Deferred();
        $written = $this->sendSseEvent($stream, 'message', $jsonData);

        if ($written) {
            $deferred->resolve(null);
        } else {
            $this->logger->debug('SSE stream buffer full, waiting for drain.', ['clientId' => $clientId]);
            $stream->once('drain', function () use ($deferred, $clientId) {
                $this->logger->debug('SSE stream drained.', ['clientId' => $clientId]);
                $deferred->resolve(null);
            });
        }

        return $deferred->promise();
    }

    /** Helper to format and write an SSE event */
    private function sendSseEvent(WritableStreamInterface $stream, string $event, string $data, ?string $id = null): bool
    {
        if (! $stream->isWritable()) {
            return false;
        }

        $frame = "event: {$event}\n";
        if ($id !== null) {
            $frame .= "id: {$id}\n";
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $frame .= "data: {$line}\n";
        }
        $frame .= "\n"; // End of event

        $this->logger->debug('Sending SSE event', ['event' => $event, 'frame' => $frame]);

        return $stream->write($frame);
    }

    /**
     * Stops the HTTP server and closes all connections.
     */
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

        $activeStreams = $this->activeSseStreams;
        $this->activeSseStreams = [];
        foreach ($activeStreams as $clientId => $stream) {
            $this->logger->debug('Closing active SSE stream', ['clientId' => $clientId]);
            unset($this->activeSseStreams[$clientId]);
            $stream->close();
        }

        $this->emit('close', ['HttpTransport closed.']);
        $this->removeAllListeners();
    }
}
