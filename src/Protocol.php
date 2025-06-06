<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use JsonException;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\JsonRpc\Messages\BatchRequest;
use PhpMcp\Server\JsonRpc\Messages\BatchResponse;
use PhpMcp\Server\JsonRpc\Messages\Error;
use PhpMcp\Server\JsonRpc\Messages\Notification;
use PhpMcp\Server\JsonRpc\Messages\Request;
use PhpMcp\Server\JsonRpc\Messages\Response;
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
            'message' => [$this, 'processMessage'],
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
     * Handles a message received from the transport.
     *
     * Processes via Processor, sends Response/Error.
     */
    public function processMessage(Request|Notification|BatchRequest $message, string $sessionId, array $context = []): void
    {
        $this->logger->debug('Message received.', ['sessionId' => $sessionId, 'message' => $message]);
        $processedPayload = null;

        if ($message instanceof BatchRequest) {
            $processedPayload = $this->processBatchRequest($message, $sessionId);
        } elseif ($message instanceof Request) {
            $processedPayload = $this->processRequest($message, $sessionId);
        } elseif ($message instanceof Notification) {
            $this->processNotification($message, $sessionId);
        }

        $this->transport->sendMessage($processedPayload, $sessionId, $context)
            ->then(function () use ($sessionId, $processedPayload) {
                $this->logger->debug('Message sent.', ['sessionId' => $sessionId, 'payload' => $processedPayload]);
            })
            ->catch(function (Throwable $e) use ($sessionId, $processedPayload) {
                $this->logger->error('Message send failed.', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
            });
    }

    /**
     * Process a batch message
     */
    private function processBatchRequest(BatchRequest $batch, string $sessionId): BatchResponse
    {
        $batchResponse = new BatchResponse();

        foreach ($batch->getNotifications() as $notification) {
            $this->processNotification($notification, $sessionId);
        }

        foreach ($batch->getRequests() as $request) {
            $response = $this->processRequest($request, $sessionId);

            $batchResponse->add($response);
        }

        return $batchResponse;
    }

    /**
     * Process a request message
     */
    private function processRequest(Request $request, string $sessionId): Response|Error
    {
        return $this->requestProcessor->processRequest($request, $sessionId);
    }

    /**
     * Process a notification message
     */
    private function processNotification(Notification $notification, string $sessionId): void
    {
        $this->requestProcessor->processNotification($notification, $sessionId);
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
