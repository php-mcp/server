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
use Throwable;

/**
 * Handles MCP message processing logic for integration into HTTP server/frameworks.
 * This class does NOT handle the HTTP connection or SSE streaming itself.
 */
class HttpTransportHandler implements TransportHandlerInterface
{
    public function __construct(
        private readonly Processor $processor,
        private readonly TransportState $transportState,
        private readonly LoggerInterface $logger
    ) {}

    public function start(): int
    {
        // throw an exception, this should never be called
        throw new \Exception('This method should never be called');
    }

    /**
     * Processes an incoming MCP request received via HTTP POST.
     *
     * Parses the JSON body, processes the request(s) via Processor,
     * and queues any responses in TransportState for later retrieval (e.g., via SSE).
     *
     * @param  string  $input  The raw JSON request body string.
     * @param  string  $clientId  A unique identifier for the connected client (e.g., session ID).
     *
     * @throws JsonException If the request body is invalid JSON.
     * @throws McpException If MCP processing fails validation or other MCP rules.
     * @throws Throwable For other unexpected processing errors.
     */
    public function handleInput(string $input, string $clientId): void
    {
        $this->logger->debug('MCP: Received request', ['client_id' => $clientId, 'input' => $input]);

        $this->transportState->updateClientActivity($clientId);

        $response = null;

        try {
            $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

            $id = $data['id'] ?? null;

            $message = $id === null
                ? Notification::fromArray($data)
                : Request::fromArray($data);

            $response = $this->processor->process($message, $clientId);
        } catch (JsonException $e) {
            $this->logger->error('MCP HTTP: JSON parse error', ['client_id' => $clientId, 'exception' => $e]);

            $response = $this->handleError($e);
        } catch (McpException $e) {
            $this->logger->error('MCP HTTP: Request processing error', ['client_id' => $clientId, 'code' => $e->getCode(), 'message' => $e->getMessage()]);

            $response = $this->handleError($e);
        } catch (Throwable $e) {
            $this->logger->error('MCP HTTP: Unexpected error processing message', ['client_id' => $clientId, 'exception' => $e]);

            $response = $this->handleError($e);
        }

        if ($response !== null) {
            $this->transportState->queueMessage($clientId, $response);
        }
    }

    public function sendResponse(string $data, string $clientId): void
    {
        echo $data;

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        $this->logger->debug('MCP: Sent response', ['json' => json_encode($data), 'client_id' => $clientId]);
    }

    protected function sendSseEvent(string $clientId, string $event, string $data, ?string $id = null): void
    {
        $data = "event: {$event}\n".($id ? "id: {$id}\n" : '')."data: {$data}\n\n";

        $this->sendResponse($data, $clientId);
    }

    /**
     * Manages the Server-Sent Events (SSE) message sending loop for a specific client.
     *
     * This method should be called within the context of an active, long-lived HTTP request
     * that has the appropriate SSE headers set by the caller.
     *
     * @param  callable  $sendEventCallback  A function provided by the caller responsible for sending data.
     *                                       Signature: function(string $event, mixed $data, ?string $id = null): void
     * @param  string  $clientId  The unique identifier for the client connection.
     * @param  string  $postEndpointUri  The URI the client MUST use for sending POST requests.
     * @param  int  $loopInterval  Seconds to sleep between loop iterations.
     * @param  int  $activityUpdateInterval  Seconds between updating client activity timestamp.
     */
    public function handleSseConnection(
        string $clientId,
        string $postEndpointUri,
        float $loopInterval = 0.05, // 50ms
        float $activityUpdateInterval = 60.0,
    ): void {
        $this->logger->info('MCP: Starting SSE stream loop', ['client_id' => $clientId]);

        // disable default disconnect checks
        ignore_user_abort(true);

        try {
            $this->sendSseEvent($clientId, 'endpoint', $postEndpointUri);
        } catch (Throwable $e) {
            $this->logger->error('MCP: Failed to send initial endpoint event. Aborting stream.', ['client_id' => $clientId, 'exception' => $e->getMessage()]);

            return;
        }

        $lastPing = microtime(true);
        $lastActivityUpdate = microtime(true);
        $eventId = 1;

        while (true) {
            if (connection_aborted()) {
                break;
            }

            // 1. Send Queued Messages
            $messages = $this->transportState->getQueuedMessages($clientId);
            foreach ($messages as $message) {
                try {
                    $messageData = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                    $this->sendSseEvent($clientId, 'message', $messageData, (string) $eventId++);
                } catch (Throwable $e) {
                    $this->logger->error('MCP: Error sending message event via callback', ['client_id' => $clientId, 'exception' => $e]);
                    break 2; // Exit loop on send error
                }
            }

            $now = microtime(true);

            // 2. Update Client Activity Timestamp
            if (($now - $lastActivityUpdate) >= $activityUpdateInterval) {
                $this->transportState->updateClientActivity($clientId);
                $lastActivityUpdate = $now;
                $this->logger->debug('MCP: Updated client activity timestamp', ['client_id' => $clientId]);
            }

            // 3. Sleep briefly
            usleep($loopInterval * 1000000);
        }

        $this->logger->info('MCP: SSE stream loop ended', ['client_id' => $clientId]);
    }

    /**
     * Cleans up resources associated with a disconnected client.
     *
     * This should be called when the HTTP connection (especially SSE) is closed.
     */
    public function cleanupClient(string $clientId): void
    {
        $this->transportState->cleanupClient($clientId);
    }

    /**
     * Helper to create a JSON-RPC error response structure.
     * Note: The ID is usually unknown when handling errors outside the processor context.
     */
    public function handleError(Throwable $error, string|int|null $id = 1): ?Response
    {
        $jsonRpcError = null;
        if ($error instanceof JsonException) {
            $jsonRpcError = McpException::parseError($error->getMessage())->toJsonRpcError();
        } elseif ($error instanceof McpException) {
            $jsonRpcError = $error->toJsonRpcError();
        } else {
            $jsonRpcError = McpException::internalError('Transport error: '.$error->getMessage(), $error)->toJsonRpcError();
        }

        return $jsonRpcError ? Response::error($jsonRpcError, id: $id) : null;
    }

    public function stop(): void
    {
        $this->logger->info('MCP HTTP: Stopping HTTP Transport.');
    }

    /**
     * Provides access to the underlying TransportState instance.
     */
    public function getTransportState(): TransportState
    {
        return $this->transportState;
    }
}
