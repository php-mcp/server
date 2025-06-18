<?php

declare(strict_types=1);

namespace PhpMcp\Server\Transports;

use Evenement\EventEmitterTrait;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Schema\JsonRpc\Message;
use PhpMcp\Schema\JsonRpc\Error;
use PhpMcp\Schema\JsonRpc\Parser;
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
class HttpServerTransport implements ServerTransportInterface, LoggerAwareInterface, LoopAwareInterface
{
    use EventEmitterTrait;

    protected LoggerInterface $logger;

    protected LoopInterface $loop;

    protected ?SocketServer $socket = null;

    protected ?HttpServer $http = null;

    /** @var array<string, ThroughStream> sessionId => SSE Stream */
    private array $activeSseStreams = [];

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
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        private readonly string $mcpPathPrefix = 'mcp',
        private readonly ?array $sslContext = null,
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

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex characters
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

            if ($method === 'GET' && $path === $this->ssePath) {
                return $this->handleSseRequest($request);
            }

            if ($method === 'POST' && $path === $this->messagePath) {
                return $this->handleMessagePostRequest($request);
            }

            $this->logger->debug('404 Not Found', ['method' => $method, 'path' => $path]);

            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        };
    }

    /** Handles a new SSE connection request */
    protected function handleSseRequest(ServerRequestInterface $request): Response
    {
        $sessionId = $this->generateId();
        $this->logger->info('New SSE connection', ['sessionId' => $sessionId]);

        $sseStream = new ThroughStream();

        $sseStream->on('close', function () use ($sessionId) {
            $this->logger->info('SSE stream closed', ['sessionId' => $sessionId]);
            unset($this->activeSseStreams[$sessionId]);
            $this->emit('client_disconnected', [$sessionId, 'SSE stream closed']);
        });

        $sseStream->on('error', function (Throwable $error) use ($sessionId) {
            $this->logger->warning('SSE stream error', ['sessionId' => $sessionId, 'error' => $error->getMessage()]);
            unset($this->activeSseStreams[$sessionId]);
            $this->emit('error', [new TransportException("SSE Stream Error: {$error->getMessage()}", 0, $error), $sessionId]);
            $this->emit('client_disconnected', [$sessionId, 'SSE stream error']);
        });

        $this->activeSseStreams[$sessionId] = $sseStream;

        $this->loop->futureTick(function () use ($sessionId, $request, $sseStream) {
            if (! isset($this->activeSseStreams[$sessionId]) || ! $sseStream->isWritable()) {
                $this->logger->warning('Cannot send initial endpoint event, stream closed/invalid early.', ['sessionId' => $sessionId]);

                return;
            }

            try {
                $baseUri = $request->getUri()->withPath($this->messagePath)->withQuery('')->withFragment('');
                $postEndpointWithId = (string) $baseUri->withQuery("clientId={$sessionId}");
                $this->sendSseEvent($sseStream, 'endpoint', $postEndpointWithId, "init-{$sessionId}");

                $this->emit('client_connected', [$sessionId]);
            } catch (Throwable $e) {
                $this->logger->error('Error sending initial endpoint event', ['sessionId' => $sessionId, 'exception' => $e]);
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
        $sessionId = $queryParams['clientId'] ?? null;
        $jsonEncodeFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if (! $sessionId || ! is_string($sessionId)) {
            $this->logger->warning('Received POST without valid clientId query parameter.');
            $error = Error::forInvalidRequest('Missing or invalid clientId query parameter');

            return new Response(400, ['Content-Type' => 'application/json'], json_encode($error, $jsonEncodeFlags));
        }

        if (! isset($this->activeSseStreams[$sessionId])) {
            $this->logger->warning('Received POST for unknown or disconnected sessionId.', ['sessionId' => $sessionId]);

            $error = Error::forInvalidRequest('Session ID not found or disconnected');

            return new Response(404, ['Content-Type' => 'application/json'], json_encode($error, $jsonEncodeFlags));
        }

        if (! str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            $error = Error::forInvalidRequest('Content-Type must be application/json');

            return new Response(415, ['Content-Type' => 'application/json'], json_encode($error, $jsonEncodeFlags));
        }

        $body = $request->getBody()->getContents();

        if (empty($body)) {
            $this->logger->warning('Received empty POST body', ['sessionId' => $sessionId]);

            $error = Error::forInvalidRequest('Empty request body');

            return new Response(400, ['Content-Type' => 'application/json'], json_encode($error, $jsonEncodeFlags));
        }

        try {
            $message = Parser::parse($body);
        } catch (Throwable $e) {
            $this->logger->error('Error parsing message', ['sessionId' => $sessionId, 'exception' => $e]);

            $error = Error::forParseError('Invalid JSON-RPC message: ' . $e->getMessage());

            return new Response(400, ['Content-Type' => 'application/json'], json_encode($error, $jsonEncodeFlags));
        }

        $this->emit('message', [$message, $sessionId]);

        return new Response(202, ['Content-Type' => 'text/plain'], 'Accepted');
    }


    /**
     * Sends a raw JSON-RPC message frame to a specific client via SSE.
     */
    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
    {
        if (! isset($this->activeSseStreams[$sessionId])) {
            return reject(new TransportException("Cannot send message: Client '{$sessionId}' not connected via SSE."));
        }

        $stream = $this->activeSseStreams[$sessionId];
        if (! $stream->isWritable()) {
            return reject(new TransportException("Cannot send message: SSE stream for client '{$sessionId}' is not writable."));
        }

        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === '') {
            return resolve(null);
        }

        $deferred = new Deferred();
        $written = $this->sendSseEvent($stream, 'message', $json);

        if ($written) {
            $deferred->resolve(null);
        } else {
            $this->logger->debug('SSE stream buffer full, waiting for drain.', ['sessionId' => $sessionId]);
            $stream->once('drain', function () use ($deferred, $sessionId) {
                $this->logger->debug('SSE stream drained.', ['sessionId' => $sessionId]);
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
        foreach ($activeStreams as $sessionId => $stream) {
            $this->logger->debug('Closing active SSE stream', ['sessionId' => $sessionId]);
            unset($this->activeSseStreams[$sessionId]);
            $stream->close();
        }

        $this->emit('close', ['HttpTransport closed.']);
        $this->removeAllListeners();
    }
}
