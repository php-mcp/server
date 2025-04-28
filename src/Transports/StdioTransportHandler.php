<?php

namespace PhpMcp\Server\Transports;

use JsonException;
use PhpMcp\Server\Contracts\TransportHandlerInterface;
use PhpMcp\Server\Exceptions\McpException;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\Processor;
use PhpMcp\Server\State\TransportState;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableResourceStream;
use React\Stream\WritableStreamInterface;
use Throwable;

/**
 * Implementation of the STDIO transport handler for MCP using React streams.
 */
class StdioTransportHandler implements TransportHandlerInterface
{
    /**
     * The event loop instance.
     */
    protected LoopInterface $loop;

    /**
     * The input stream.
     */
    protected ?ReadableStreamInterface $inputStream = null;

    /**
     * The output stream.
     */
    protected ?WritableStreamInterface $outputStream = null;

    /**
     * Buffered input data.
     */
    protected string $buffer = '';

    private const CLIENT_ID = 'stdio_client';

    /**
     * Create a new STDIO transport handler.
     *
     * @param  Processor  $processor  The MCP processor.
     * @param  TransportState  $transportState  The transport state.
     * @param  LoggerInterface  $logger  The PSR logger.
     */
    public function __construct(
        private readonly Processor $processor,
        private readonly TransportState $transportState,
        private readonly LoggerInterface $logger
    ) {
        $this->loop = Loop::get();
    }

    /**
     * Start processing messages.
     *
     * @return int Exit code>
     */
    public function start(): int
    {
        try {
            $this->logger->info('MCP: Starting STDIO Transport Handler.');
            fwrite(STDERR, "MCP: Starting STDIO Transport Handler...\n");

            $this->inputStream = new ReadableResourceStream(STDIN, $this->loop);
            $this->outputStream = new WritableResourceStream(STDOUT, $this->loop);

            $this->inputStream->on('error', function (Throwable $error) {
                $this->logger->error('MCP: Input stream error', ['exception' => $error]);
                $this->stop();
            });

            $this->outputStream->on('error', function (Throwable $error) {
                $this->logger->error('MCP: Output stream error', ['exception' => $error]);
                $this->stop();
            });

            $this->inputStream->on('data', fn ($data) => $this->handle($data, self::CLIENT_ID));

            $this->loop->addPeriodicTimer(0.5, fn () => $this->checkQueuedMessages());
            $this->loop->addPeriodicTimer(60, fn () => $this->transportState->updateClientActivity(self::CLIENT_ID));
            $this->loop->addSignal(SIGTERM, fn () => $this->stop());
            $this->loop->addSignal(SIGINT, fn () => $this->stop());

            $this->logger->info('MCP: STDIO Transport ready and waiting for input...');
            fwrite(STDERR, "MCP: STDIO Transport ready and waiting for input...\n");

            $this->loop->run();

            return 0;
        } catch (Throwable $e) {
            $this->logger->critical('MCP: Fatal error in STDIO transport handler', ['exception' => $e]);
            $this->handleError($e);

            return 1;
        }
    }

    /**
     * Handle incoming data according to MCP STDIO transport.
     */
    public function handle(string|array $input, string $clientId): bool
    {
        if (! is_string($input)) {
            return false;
        }

        $this->buffer .= $input;

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            if (! empty($line)) {
                $this->logger->debug('MCP: Received message', ['message' => $line]);
                $this->handleInput(trim($line), $clientId);
            }
        }

        return true;
    }

    /**
     * Process a complete line (JSON message)
     */
    public function handleInput(string $input, string $clientId): void
    {
        $id = null;
        $response = null;
        $this->transportState->updateClientActivity($clientId);

        try {
            $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            $id = $data['id'] ?? null;

            $message = $id === null
                ? Notification::fromArray($data)
                : Request::fromArray($data);

            $response = $this->processor->process($message, $clientId);
        } catch (JsonException $e) {
            $this->logger->error('MCP: Error processing message: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            $response = $this->handleError($e);
        } catch (McpException $e) {
            $this->logger->error('MCP: Error processing message: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            $response = $this->handleError($e, $id);
        } catch (Throwable $e) {
            $this->logger->error('MCP: Error processing message: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            $response = $this->handleError($e, $id);
        }

        if ($response) {
            if (! $this->outputStream || ! $this->outputStream->isWritable()) {
                $this->logger->error('MCP: Cannot send response, output stream is not writable.');

                return;
            }

            $jsonResponse = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->sendResponse($jsonResponse, $clientId);
        }
    }

    /**
     * Send a JSON-RPC response message.
     *
     * @param  Response|Response[]|array  $message  The message to send
     * @param  string  $clientId  The client ID
     */
    public function sendResponse(string $data, string $clientId): void
    {
        $this->outputStream->write($data."\n");
        $this->logger->debug('MCP: Sent response', ['json' => $data, 'client_id' => $clientId]);
    }

    /**
     * Handle an error that occurred in the transport.
     *
     * @param  Throwable  $error  The error that occurred
     * @return Response|null Error response or null if error was handled
     */
    public function handleError(Throwable $error, string|int|null $id = 1): ?Response
    {
        $id ??= 1;
        $jsonRpcError = null;

        if ($error instanceof JsonException) {
            $jsonRpcError = McpException::parseError($error->getMessage())->toJsonRpcError();
        } elseif ($error instanceof McpException) {
            $jsonRpcError = $error->toJsonRpcError();
        } else {
            $jsonRpcError = McpException::internalError('Transport error: '.$error->getMessage())->toJsonRpcError();
        }

        $this->logger->error('MCP: Transport Error', [
            'error_code' => $jsonRpcError?->code ?? $error->getCode(),
            'message' => $jsonRpcError?->message ?? $error->getMessage(),
        ]);

        return $jsonRpcError ? Response::error($jsonRpcError, $id) : null;
    }

    /**
     * Close the transport connection.
     */
    public function stop(): void
    {
        $this->logger->info('MCP: Closing STDIO Transport.');
        fwrite(STDERR, "MCP: Closing STDIO Transport...\n");

        $this->transportState->cleanupClient(self::CLIENT_ID);

        if ($this->inputStream) {
            $this->inputStream->close();
            $this->inputStream = null;
        }
        if ($this->outputStream) {
            $this->outputStream->close();
            $this->outputStream = null;
        }
        $this->loop->stop();
    }

    protected function checkQueuedMessages(): void
    {
        try {
            $messages = $this->transportState->getQueuedMessages(self::CLIENT_ID);

            foreach ($messages as $messageData) {
                if (! $this->outputStream || ! $this->outputStream->isWritable()) {
                    $this->logger->warning('MCP: Output stream not writable, dropping queued message.', ['message' => $messageData]);

                    continue;
                }
                $jsonResponse = json_encode($messageData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->outputStream->write($jsonResponse."\n");
                $this->logger->debug('MCP: Sent message from queue', ['json' => $jsonResponse]);
            }
        } catch (Throwable $e) {
            $this->logger->error('MCP: Error processing or sending queued messages', ['exception' => $e]);
        }
    }
}
