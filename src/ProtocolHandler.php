<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use JsonException;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Exception\ProtocolException;
use PhpMcp\Server\JsonRpc\Message;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\reject;

/**
 * Bridges the core MCP Processor/Registry/State logic with a ServerTransportInterface
 * by listening to transport events and processing incoming messages.
 *
 * This handler manages the JSON-RPC parsing, processing delegation, and response sending
 * based on events received from the transport layer.
 */
class ProtocolHandler
{
    protected ?ServerTransportInterface $transport = null;

    /** Stores listener references for proper removal */
    protected array $listeners = [];

    public function __construct(
        protected readonly Processor $processor,
        protected readonly ClientStateManager $clientStateManager,
        protected readonly LoggerInterface $logger,
        protected readonly LoopInterface $loop
    ) {
    }

    /**
     * Binds this handler to a transport instance by attaching event listeners.
     * Does NOT start the transport's listening process itself.
     */
    public function bindTransport(ServerTransportInterface $transport): void
    {
        if ($this->transport !== null) {
            $this->unbindTransport();
        }

        $this->transport = $transport;

        $this->listeners = [
            'message' => [$this, 'handleRawMessage'],
            'client_connected' => [$this, 'handleClientConnected'],
            'client_disconnected' => [$this, 'handleClientDisconnected'],
            'error' => [$this, 'handleTransportError'],
        ];

        $this->transport->on('message', $this->listeners['message']);
        $this->transport->on('client_connected', $this->listeners['client_connected']);
        $this->transport->on('client_disconnected', $this->listeners['client_disconnected']);
        $this->transport->on('error', $this->listeners['error']);
    }

    /**
     * Detaches listeners from the current transport.
     */
    public function unbindTransport(): void
    {
        if ($this->transport && ! empty($this->listeners)) {
            $this->transport->removeListener('message', $this->listeners['message']);
            $this->transport->removeListener('client_connected', $this->listeners['client_connected']);
            $this->transport->removeListener('client_disconnected', $this->listeners['client_disconnected']);
            $this->transport->removeListener('error', $this->listeners['error']);
        }

        $this->transport = null;
        $this->listeners = [];
    }

    /**
     * Handles a raw message frame received from the transport.
     *
     * Parses JSON, validates structure, processes via Processor, sends Response/Error.
     */
    public function handleRawMessage(string $rawJsonRpcFrame, string $clientId): void
    {
        $this->logger->debug('Received message', ['clientId' => $clientId, 'frame' => $rawJsonRpcFrame]);
        $responseToSend = null;
        $parsedMessage = null;
        $messageData = null;

        try {
            $messageData = json_decode($rawJsonRpcFrame, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($messageData)) {
                throw new ProtocolException('Invalid JSON received (not an object/array).');
            }

            $parsedMessage = $this->parseMessageData($messageData);

            if ($parsedMessage === null) {
                throw McpServerException::invalidRequest('Invalid MCP/JSON-RPC message structure.');
            }

            $responseToSend = $this->processor->process($parsedMessage, $clientId);

        } catch (JsonException $e) {
            $this->logger->error("JSON Parse Error for client {$clientId}", ['error' => $e->getMessage()]);
            // ID is null for Parse Error according to JSON-RPC 2.0 spec
            $responseToSend = Response::error(McpServerException::parseError($e->getMessage())->toJsonRpcError(), null);
        } catch (McpServerException $e) {
            $this->logger->warning("MCP Exception during processing for client {$clientId}", ['code' => $e->getCode(), 'error' => $e->getMessage()]);
            $id = $this->getRequestId($parsedMessage, $messageData);
            $responseToSend = Response::error($e->toJsonRpcError(), $id);
        } catch (Throwable $e) {
            $this->logger->error("Unexpected processing error for client {$clientId}", ['exception' => $e]);
            $id = $this->getRequestId($parsedMessage, $messageData);
            $responseToSend = Response::error(McpServerException::internalError()->toJsonRpcError(), $id);
        }

        if ($responseToSend instanceof Response) {
            $this->sendResponse($clientId, $responseToSend);
        } elseif ($parsedMessage instanceof Request && $responseToSend === null) {
            // Should not happen if Processor is correct, but safeguard
            $this->logger->error('Processor failed to return a Response for a Request', ['clientId' => $clientId, 'method' => $parsedMessage->method, 'id' => $parsedMessage->id]);
            $responseToSend = Response::error(McpServerException::internalError('Processing failed to generate a response.')->toJsonRpcError(), $parsedMessage->id);
            $this->sendResponse($clientId, $responseToSend);
        }
        // If $parsedMessage was a Notification, $responseToSend should be null, and we send nothing.
    }

    /** Safely gets the request ID from potentially parsed or raw message data */
    private function getRequestId(Request|Notification|null $parsed, ?array $rawData): string|int|null
    {
        if ($parsed instanceof Request) {
            return $parsed->id;
        }
        // Attempt fallback to raw data if parsing failed but JSON decoded
        if (is_array($rawData) && isset($rawData['id']) && (is_string($rawData['id']) || is_int($rawData['id']))) {
            return $rawData['id'];
        }

        // Null ID for parse errors or notifications
        return null;
    }

    /** Sends a Response object via the transport */
    private function sendResponse(string $clientId, Response $response): void
    {
        if ($this->transport === null) {
            $this->logger->error('Cannot send response, transport is not bound.', ['clientId' => $clientId]);

            return;
        }

        try {
            $responseData = $response->toArray();
            $jsonResponse = json_encode($responseData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Frame the message (e.g., add newline for stdio) - Transport *should* handle framing?
            // Let's assume transport needs the raw JSON string for now. Framing added here.
            // TODO: Revisit if framing should be transport's responsibility. For now, add newline.
            $framedMessage = $jsonResponse."\n";

            $this->transport->sendToClientAsync($clientId, $framedMessage)
                ->catch(
                    function (Throwable $e) use ($clientId, $response) {
                        $this->logger->error('Transport failed to send response.', [
                            'clientId' => $clientId,
                            'responseId' => $response->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                );

            $this->logger->debug('Sent response', ['clientId' => $clientId, 'frame' => $framedMessage]);

        } catch (JsonException $e) {
            $this->logger->error('Failed to encode response to JSON.', ['clientId' => $clientId, 'responseId' => $response->id, 'error' => $e->getMessage()]);
            // We can't send *this* error back easily if encoding failed.
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error during response preparation/sending.', ['clientId' => $clientId, 'responseId' => $response->id, 'exception' => $e]);
        }
    }

    /**
     * Sends a Notification object via the transport to a specific client.
     *
     * (Primarily used internally or by advanced framework integrations)
     */
    public function sendNotification(string $clientId, Notification $notification): PromiseInterface
    {
        if ($this->transport === null) {
            $this->logger->error('Cannot send notification, transport not bound.', ['clientId' => $clientId]);

            return reject(new McpServerException('Transport not bound'));
        }
        try {
            $jsonNotification = json_encode($notification->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $framedMessage = $jsonNotification."\n"; // Add framing
            $this->logger->debug('Sending notification', ['clientId' => $clientId, 'method' => $notification->method]);

            return $this->transport->sendToClientAsync($clientId, $framedMessage);
        } catch (JsonException $e) {
            $this->logger->error('Failed to encode notification to JSON.', ['clientId' => $clientId, 'method' => $notification->method, 'error' => $e->getMessage()]);

            return reject(new McpServerException('Failed to encode notification: '.$e->getMessage(), 0, $e));
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error sending notification.', ['clientId' => $clientId, 'method' => $notification->method, 'exception' => $e]);

            return reject(new McpServerException('Failed to send notification: '.$e->getMessage(), 0, $e));
        }
    }

    // --- Transport Event Handlers ---

    /** Handles 'client_connected' event from the transport */
    public function handleClientConnected(string $clientId): void
    {
        $this->logger->info('Client connected', ['clientId' => $clientId]);
    }

    /** Handles 'client_disconnected' event from the transport */
    public function handleClientDisconnected(string $clientId, ?string $reason = null): void
    {
        $this->logger->info('Client disconnected', ['clientId' => $clientId, 'reason' => $reason ?? 'N/A']);
        $this->clientStateManager->cleanupClient($clientId);
    }

    /** Handles 'error' event from the transport */
    public function handleTransportError(Throwable $error, ?string $clientId = null): void
    {
        $context = ['error' => $error->getMessage(), 'exception_class' => get_class($error)];

        if ($clientId) {
            $context['clientId'] = $clientId;
            $this->logger->error('Transport error for client', $context);
            $this->clientStateManager->cleanupClient($clientId);
            // Should we close the transport here? Depends if error is fatal for the client only or the whole transport.
            // If the transport can recover or handles other clients, maybe not. Let transport decide?
        } else {
            $this->logger->error('General transport error', $context);
            // This might be fatal, perhaps signal the main loop to stop?
            // Or maybe just log it. For now, log only.
        }
    }

    /** Parses raw array into Request or Notification */
    private function parseMessageData(array $data): Request|Notification|null
    {
        try {
            if (isset($data['method'])) {
                if (isset($data['id']) && $data['id'] !== null) {
                    return Request::fromArray($data);
                } else {
                    return Notification::fromArray($data);
                }
            }
        } catch (ProtocolException $e) {
            throw McpServerException::invalidRequest('Invalid JSON-RPC structure: '.$e->getMessage(), $e);
        } catch (Throwable $e) {
            throw new ProtocolException('Unexpected error parsing message structure: '.$e->getMessage(), McpServerException::CODE_PARSE_ERROR, null, $e);
        }

        throw McpServerException::invalidRequest("Message must contain a 'method' field.");
    }
}
