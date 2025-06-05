<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use JsonException;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Exception\ProtocolException;
use PhpMcp\Server\JsonRpc\Batch;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\Support\RequestProcessor;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bridges the core MCP Processor logic with a ServerTransportInterface
 * by listening to transport events and processing incoming messages.
 *
 * This handler manages the JSON-RPC parsing, processing delegation, and response sending
 * based on events received from the transport layer.
 */
class Protocol
{
    protected ?ServerTransportInterface $transport = null;

    /** Stores listener references for proper removal */
    protected array $listeners = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly RequestProcessor $requestProcessor,
    ) {}

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
            'message' => [$this, 'handleMessage'],
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
    public function handleMessage(string $rawJsonRpcFrame, string $sessionId): void
    {
        $this->logger->debug('Received message', ['sessionId' => $sessionId, 'frame' => $rawJsonRpcFrame]);

        try {
            $messageData = $this->decodeJsonMessage($rawJsonRpcFrame);

            $message = $this->parseMessage($messageData);

            $response = $this->processMessage($message, $sessionId);

            if ($response) {
                $this->sendResponse($sessionId, $response);
            }
        } catch (JsonException $e) {
            $this->handleJsonParseError($e, $sessionId);
        } catch (ProtocolException $e) {
            $this->handleProtocolError($e, $sessionId);
        } catch (Throwable $e) {
            $this->handleUnexpectedError($e, $sessionId);
        }
    }

    /**
     * Decodes a raw JSON message string into an array
     */
    private function decodeJsonMessage(string $rawJsonRpcFrame): array
    {
        $messageData = json_decode($rawJsonRpcFrame, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($messageData)) {
            throw ProtocolException::invalidRequest('Invalid JSON-RPC message: payload is not a JSON object or array.');
        }

        return $messageData;
    }

    /**
     * Parses message data into Request, Notification, Batch, or throws an exception
     */
    private function parseMessage(array $messageData): Request|Notification|Batch
    {
        $isBatch = array_is_list($messageData) && count($messageData) > 0 && is_array($messageData[0] ?? null);

        if ($isBatch) {
            return Batch::fromArray($messageData);
        } elseif (isset($messageData['method'])) {
            if (isset($messageData['id']) && $messageData['id'] !== null) {
                return Request::fromArray($messageData);
            } else {
                return Notification::fromArray($messageData);
            }
        }

        throw McpServerException::invalidRequest("Message must contain a 'method' field.");
    }

    /**
     * Process a message based on its type
     */
    private function processMessage(Request|Notification|Batch $message, string $sessionId): ?string
    {
        return match (true) {
            $message instanceof Batch => $this->processBatchMessage($message, $sessionId),
            $message instanceof Request => $this->processRequestMessage($message, $sessionId),
            $message instanceof Notification => $this->processNotificationMessage($message, $sessionId),
        };
    }

    /**
     * Process a batch message
     */
    private function processBatchMessage(Batch $batch, string $sessionId): ?string
    {
        $responsesToSend = [];

        foreach ($batch->getRequests() as $item) {
            try {
                if ($item instanceof Request) {
                    $itemResponse = $this->requestProcessor->process($item, $sessionId);

                    if ($itemResponse instanceof Response) {
                        $responsesToSend[] = $itemResponse;
                    } elseif ($itemResponse === null) {
                        $this->logger->error(
                            'Processor failed to return a Response for a Request in batch',
                            ['sessionId' => $sessionId, 'method' => $item->method, 'id' => $item->id]
                        );
                        $responsesToSend[] = Response::error(
                            McpServerException::internalError('Processing failed to generate a response for batch item.')->toJsonRpcError(),
                            $item->id
                        );
                    }
                } elseif ($item instanceof Notification) {
                    $this->requestProcessor->process($item, $sessionId);
                }
            } catch (McpServerException $e) {
                $itemId = $item instanceof Request ? $item->id : null;
                $responsesToSend[] = Response::error($e->toJsonRpcError(), $itemId);
            } catch (Throwable $e) {
                $this->logger->error("Unexpected processing error for batch item", ['sessionId' => $sessionId, 'exception' => $e]);
                $itemId = $item instanceof Request ? $item->id : null;
                $responsesToSend[] = Response::error(
                    McpServerException::internalError('Internal error processing batch item.')->toJsonRpcError(),
                    $itemId
                );
            }
        }

        if (!empty($responsesToSend)) {
            $batchResponseArray = array_map(fn(Response $r) => $r->toArray(), $responsesToSend);
            return json_encode($batchResponseArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * Process a request message
     */
    private function processRequestMessage(Request $request, string $sessionId): string
    {
        try {
            $response = $this->requestProcessor->process($request, $sessionId);

            if ($response instanceof Response) {
                return json_encode($response->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $this->logger->error(
                    'Processor failed to return a Response for a Request',
                    ['sessionId' => $sessionId, 'method' => $request->method, 'id' => $request->id]
                );
                $errorResponse = Response::error(
                    McpServerException::internalError('Processing failed to generate a response.')->toJsonRpcError(),
                    $request->id
                );
                return json_encode($errorResponse->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } catch (McpServerException $e) {
            $errorResponse = Response::error($e->toJsonRpcError(), $request->id);
            return json_encode($errorResponse->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $this->logger->error("Unexpected error processing request", ['sessionId' => $sessionId, 'exception' => $e]);
            $errorResponse = Response::error(
                McpServerException::internalError('Internal error processing request.')->toJsonRpcError(),
                $request->id
            );
            return json_encode($errorResponse->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Process a notification message
     */
    private function processNotificationMessage(Notification $notification, string $sessionId): ?string
    {
        try {
            $this->requestProcessor->process($notification, $sessionId);
            return null;
        } catch (Throwable $e) {
            $this->logger->error(
                "Error processing notification",
                ['sessionId' => $sessionId, 'method' => $notification->method, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Handle a JSON parse error
     */
    private function handleJsonParseError(JsonException $e, string $sessionId): void
    {
        $this->logger->error("JSON Parse Error", ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
        $responseToSend = Response::error(McpServerException::parseError($e->getMessage())->toJsonRpcError(), null);
        $responseJson = json_encode($responseToSend->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->sendResponse($sessionId, $responseJson);
    }

    /**
     * Handle a protocol error
     */
    private function handleProtocolError(ProtocolException $e, string $sessionId): void
    {
        $this->logger->error("Invalid JSON-RPC structure", ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
        $responseToSend = Response::error($e->toJsonRpcError(), null);
        $responseJson = json_encode($responseToSend->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->sendResponse($sessionId, $responseJson);
    }

    /**
     * Handle an unexpected error
     */
    private function handleUnexpectedError(Throwable $e, string $sessionId): void
    {
        $this->logger->error("Unexpected error", ['sessionId' => $sessionId, 'exception' => $e]);
        $responseToSend = Response::error(McpServerException::internalError('Internal server error.')->toJsonRpcError(), null);
        $responseJson = json_encode($responseToSend->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->sendResponse($sessionId, $responseJson);
    }


    private function sendResponse(string $sessionId, string $response): void
    {
        if ($this->transport === null) {
            $this->logger->error('Cannot send response, there is no transport bound.', ['sessionId' => $sessionId]);
            return;
        }

        $framedMessage = $response . "\n";

        $this->transport->sendToClientAsync($sessionId, $framedMessage)
            ->then(function () use ($sessionId, $framedMessage) {
                $this->logger->debug('Sent response', ['sessionId' => $sessionId, 'frame' => $framedMessage]);
            })
            ->catch(function (Throwable $e) use ($sessionId) {
                $this->logger->error('Transport failed to send response.', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
            });
    }

    /**
     * Handles 'client_connected' event from the transport
     */
    public function handleClientConnected(string $clientId): void
    {
        $this->logger->info('Client connected', ['clientId' => $clientId]);
    }

    /**
     * Handles 'client_disconnected' event from the transport
     */
    public function handleClientDisconnected(string $clientId, ?string $reason = null): void
    {
        $this->logger->info('Client disconnected', ['clientId' => $clientId, 'reason' => $reason ?? 'N/A']);
    }

    /**
     * Handles 'error' event from the transport
     */
    public function handleTransportError(Throwable $error, ?string $clientId = null): void
    {
        $context = ['error' => $error->getMessage(), 'exception_class' => get_class($error)];

        if ($clientId) {
            $context['clientId'] = $clientId;
            $this->logger->error('Transport error for client', $context);
        } else {
            $this->logger->error('General transport error', $context);
        }
    }
}
