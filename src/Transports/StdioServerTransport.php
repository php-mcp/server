<?php

declare(strict_types=1);

namespace PhpMcp\Server\Transports;

use Evenement\EventEmitterTrait;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Schema\JsonRpc\Error;
use PhpMcp\Schema\JsonRpc\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableResourceStream;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableResourceStream;
use React\Stream\WritableStreamInterface;
use Throwable;

use function React\Promise\reject;

/**
 * Implementation of the STDIO server transport using ReactPHP Process and Streams.
 * Listens on STDIN, writes to STDOUT, and emits events for the Protocol.
 */
class StdioServerTransport implements ServerTransportInterface, LoggerAwareInterface, LoopAwareInterface
{
    use EventEmitterTrait;

    protected LoggerInterface $logger;

    protected LoopInterface $loop;

    protected ?Process $process = null;

    protected ?ReadableStreamInterface $stdin = null;

    protected ?WritableStreamInterface $stdout = null;

    protected string $buffer = '';

    protected bool $closing = false;

    protected bool $listening = false;

    private const CLIENT_ID = 'stdio';

    /**
     * Constructor takes optional stream resources.
     * Defaults to STDIN and STDOUT if not provided.
     * Dependencies like Logger and Loop are injected via setters.
     *
     * @param  resource|null  $inputStreamResource  The readable resource (e.g., STDIN).
     * @param  resource|null  $outputStreamResource  The writable resource (e.g., STDOUT).
     *
     * @throws TransportException If provided resources are invalid.
     */
    public function __construct(
        protected $inputStreamResource = STDIN,
        protected $outputStreamResource = STDOUT
    ) {
        if (str_contains(PHP_OS, 'WIN') && ($this->inputStreamResource === STDIN && $this->outputStreamResource === STDOUT)) {
            $message = 'STDIN and STDOUT are not supported as input and output stream resources' .
                'on Windows due to PHP\'s limitations with non blocking pipes.' .
                'Please use WSL or HttpServerTransport, or if you are advanced, provide your own stream resources.';

            throw new TransportException($message);
        }

        // if (str_contains(PHP_OS, 'WIN')) {
        //     $this->inputStreamResource = pclose(popen('winpty -c "'.$this->inputStreamResource.'"', 'r'));
        //     $this->outputStreamResource = pclose(popen('winpty -c "'.$this->outputStreamResource.'"', 'w'));
        // }

        if (! is_resource($this->inputStreamResource) || get_resource_type($this->inputStreamResource) !== 'stream') {
            throw new TransportException('Invalid input stream resource provided.');
        }

        if (! is_resource($this->outputStreamResource) || get_resource_type($this->outputStreamResource) !== 'stream') {
            throw new TransportException('Invalid output stream resource provided.');
        }

        $this->logger = new NullLogger();
        $this->loop = Loop::get();
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
     * Starts listening on STDIN.
     *
     * @throws TransportException If already listening or streams cannot be opened.
     */
    public function listen(): void
    {
        if ($this->listening) {
            throw new TransportException('Stdio transport is already listening.');
        }

        if ($this->closing) {
            throw new TransportException('Cannot listen, transport is closing/closed.');
        }

        try {
            $this->stdin = new ReadableResourceStream($this->inputStreamResource, $this->loop);
            $this->stdout = new WritableResourceStream($this->outputStreamResource, $this->loop);
        } catch (Throwable $e) {
            $this->logger->error('Failed to open STDIN/STDOUT streams.', ['exception' => $e]);
            throw new TransportException("Failed to open standard streams: {$e->getMessage()}", 0, $e);
        }

        $this->stdin->on('data', function ($chunk) {
            $this->buffer .= $chunk;
            $this->processBuffer();
        });

        $this->stdin->on('error', function (Throwable $error) {
            $this->logger->error('STDIN stream error.', ['error' => $error->getMessage()]);
            $this->emit('error', [new TransportException("STDIN error: {$error->getMessage()}", 0, $error), self::CLIENT_ID]);
            $this->close();
        });

        $this->stdin->on('close', function () {
            $this->logger->info('STDIN stream closed.');
            $this->emit('client_disconnected', [self::CLIENT_ID, 'STDIN Closed']);
            $this->close();
        });

        $this->stdout->on('error', function (Throwable $error) {
            $this->logger->error('STDOUT stream error.', ['error' => $error->getMessage()]);
            $this->emit('error', [new TransportException("STDOUT error: {$error->getMessage()}", 0, $error), self::CLIENT_ID]);
            $this->close();
        });

        try {
            $signalHandler = function (int $signal) {
                $this->logger->info("Received signal {$signal}, shutting down.");
                $this->close();
            };
            $this->loop->addSignal(SIGTERM, $signalHandler);
            $this->loop->addSignal(SIGINT, $signalHandler);
        } catch (Throwable $e) {
            $this->logger->debug('Signal handling not supported by current event loop.');
        }

        $this->logger->info('Server is up and listening on STDIN 🚀');

        $this->listening = true;
        $this->closing = false;
        $this->emit('ready');
        $this->emit('client_connected', [self::CLIENT_ID]);
    }

    /** Processes the internal buffer to find complete lines/frames. */
    private function processBuffer(): void
    {
        while (str_contains($this->buffer, "\n")) {
            $pos = strpos($this->buffer, "\n");
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            $trimmedLine = trim($line);
            if (empty($trimmedLine)) {
                continue;
            }

            try {
                $message = Parser::parse($trimmedLine);
            } catch (Throwable $e) {
                $this->logger->error('Error parsing message', ['exception' => $e]);
                $error = Error::forParseError("Invalid JSON: " . $e->getMessage());
                $this->sendMessage($error, self::CLIENT_ID);
                continue;
            }

            $this->emit('message', [$message, self::CLIENT_ID]);
        }
    }

    /**
     * Sends a raw, framed message to STDOUT.
     */
    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
    {
        if ($this->closing || ! $this->stdout || ! $this->stdout->isWritable()) {
            return reject(new TransportException('Stdio transport is closed or STDOUT is not writable.'));
        }

        $deferred = new Deferred();
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $written = $this->stdout->write($json . "\n");

        if ($written) {
            $deferred->resolve(null);
        } else {
            $this->logger->debug('STDOUT buffer full, waiting for drain.');
            $this->stdout->once('drain', function () use ($deferred) {
                $this->logger->debug('STDOUT drained.');
                $deferred->resolve(null);
            });
        }

        return $deferred->promise();
    }

    /**
     * Stops listening and cleans up resources.
     */
    public function close(): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;
        $this->listening = false;
        $this->logger->info('Closing Stdio transport...');

        $this->stdin?->close();
        $this->stdout?->close();

        $this->stdin = null;
        $this->stdout = null;

        $this->emit('close', ['StdioTransport closed.']);
        $this->removeAllListeners();
    }
}
