<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Messages\BatchRequest;
use PhpMcp\Server\JsonRpc\Messages\BatchResponse;
use PhpMcp\Server\JsonRpc\Messages\Error;
use PhpMcp\Server\JsonRpc\Messages\Notification;
use PhpMcp\Server\JsonRpc\Messages\Request;
use PhpMcp\Server\JsonRpc\Messages\Response;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Session\SubscriptionManager;
use PhpMcp\Server\Support\RequestHandler;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\reject;

/**
 * Bridges the core MCP Processor logic with a ServerTransportInterface
 * by listening to transport events and processing incoming messages.
 *
 * This handler manages the JSON-RPC parsing, processing delegation, and response sending
 * based on events received from the transport layer.
 */
class Protocol
{
    public const LATEST_PROTOCOL_VERSION = '2025-03-26';
    public const SUPPORTED_PROTOCOL_VERSIONS = [self::LATEST_PROTOCOL_VERSION, '2024-11-05'];

    protected ?ServerTransportInterface $transport = null;

    protected LoggerInterface $logger;

    /** Stores listener references for proper removal */
    protected array $listeners = [];

    public function __construct(
        protected Configuration $configuration,
        protected Registry $registry,
        protected SessionManager $sessionManager,
        protected ?RequestHandler $requestHandler = null,
        protected ?SubscriptionManager $subscriptionManager = null,
    ) {
        $this->logger = $this->configuration->logger;
        $this->subscriptionManager ??= new SubscriptionManager($this->logger);
        $this->requestHandler ??= new RequestHandler($this->configuration, $this->registry, $this->subscriptionManager);

        $this->sessionManager->on('session_deleted', function (string $sessionId) {
            $this->subscriptionManager->cleanupSession($sessionId);
        });

        $this->registry->on('list_changed', function (string $listType) {
            $this->handleListChanged($listType);
        });
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

        $session = $this->sessionManager->getSession($sessionId);

        $response = null;

        if ($message instanceof BatchRequest) {
            $response = $this->processBatchRequest($message, $session);
        } elseif ($message instanceof Request) {
            $response = $this->processRequest($message, $session);
        } elseif ($message instanceof Notification) {
            $this->processNotification($message, $session);
        }

        $session->save();

        if ($response === null) {
            return;
        }

        $this->transport->sendMessage($response, $sessionId, $context)
            ->then(function () use ($sessionId, $response) {
                $this->logger->debug('Response sent.', ['sessionId' => $sessionId, 'payload' => $response]);
            })
            ->catch(function (Throwable $e) use ($sessionId) {
                $this->logger->error('Failed to send response.', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
            });
    }

    /**
     * Process a batch message
     */
    private function processBatchRequest(BatchRequest $batch, SessionInterface $session): BatchResponse
    {
        $batchResponse = new BatchResponse();

        foreach ($batch->getNotifications() as $notification) {
            $this->processNotification($notification, $session);
        }

        foreach ($batch->getRequests() as $request) {
            $response = $this->processRequest($request, $session);

            $batchResponse->add($response);
        }

        return $batchResponse;
    }

    /**
     * Process a request message
     */
    private function processRequest(Request $request, SessionInterface $session): Response|Error
    {
        $method = $request->method;
        $params = $request->params;

        try {
            if ($method !== 'initialize') {
                $this->assertSessionInitialized($session);
            }

            $this->assertRequestCapability($method);

            $result = match ($method) {
                'initialize' => $this->requestHandler->handleInitialize($params, $session),
                'ping' => $this->requestHandler->handlePing($session),
                'tools/list' => $this->requestHandler->handleToolList($params),
                'tools/call' => $this->requestHandler->handleToolCall($params),
                'resources/list' => $this->requestHandler->handleResourcesList($params),
                'resources/read' => $this->requestHandler->handleResourceRead($params),
                'resources/subscribe' => $this->requestHandler->handleResourceSubscribe($params, $session),
                'resources/unsubscribe' => $this->requestHandler->handleResourceUnsubscribe($params, $session),
                'resources/templates/list' => $this->requestHandler->handleResourceTemplateList($params),
                'prompts/list' => $this->requestHandler->handlePromptsList($params),
                'prompts/get' => $this->requestHandler->handlePromptGet($params),
                'logging/setLevel' => $this->requestHandler->handleLoggingSetLevel($params, $session),
                'completion/complete' => $this->requestHandler->handleCompletionComplete($params, $session),
                default => throw McpServerException::methodNotFound($method),
            };

            return Response::make($result, $request->id);
        } catch (McpServerException $e) {
            $this->logger->debug('MCP Processor caught McpServerException', ['method' => $method, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => $e->getData()]);

            return $e->toJsonRpcError($request->id);
        } catch (Throwable $e) {
            $this->logger->error('MCP Processor caught unexpected error', ['method' => $method, 'exception' => $e->getMessage()]);

            return new Error(
                jsonrpc: '2.0',
                id: $request->id,
                code: Error::CODE_INTERNAL_ERROR,
                message: 'Internal error processing method ' . $method,
                data: $e->getMessage()
            );
        }
    }

    /**
     * Process a notification message
     */
    private function processNotification(Notification $notification, SessionInterface $session): void
    {
        $method = $notification->method;
        $params = $notification->params;

        try {
            if ($method === 'notifications/initialized') {
                $this->requestHandler->handleNotificationInitialized($params, $session);
            }
        } catch (Throwable $e) {
            $this->logger->error('Error while processing notification', ['method' => $method, 'exception' => $e->getMessage()]);
            return;
        }
    }

    /**
     * Send a notification to a session
     */
    public function sendNotification(string $sessionId, Notification $notification): PromiseInterface
    {
        if ($this->transport === null) {
            $this->logger->error('Cannot send notification, transport not bound', [
                'sessionId' => $sessionId,
                'method' => $notification->method
            ]);
            return reject(new McpServerException('Transport not bound'));
        }

        try {
            return $this->transport->sendMessage($notification, $sessionId, []);
        } catch (Throwable $e) {
            $this->logger->error('Failed to send notification', [
                'sessionId' => $sessionId,
                'method' => $notification->method,
                'error' => $e->getMessage()
            ]);
            return reject(new McpServerException('Failed to send notification: ' . $e->getMessage()));
        }
    }

    /**
     * Notify subscribers about resource content change
     */
    public function notifyResourceChanged(string $uri): void
    {
        $subscribers = $this->subscriptionManager->getSubscribers($uri);

        if (empty($subscribers)) {
            return;
        }

        $notification = Notification::make(
            'notifications/resources/updated',
            ['uri' => $uri]
        );

        foreach ($subscribers as $sessionId) {
            $this->sendNotification($sessionId, $notification);
        }

        $this->logger->debug("Sent resource change notification", [
            'uri' => $uri,
            'subscriber_count' => count($subscribers)
        ]);
    }

    /**
     * Validate that a session is initialized
     */
    private function assertSessionInitialized(SessionInterface $session): void
    {
        if (!$session->get('initialized', false)) {
            throw McpServerException::invalidRequest('Client session not initialized.');
        }
    }

    /**
     * Assert that a request method is enabled
     */
    private function assertRequestCapability(string $method): void
    {
        $capabilities = $this->configuration->capabilities;

        switch ($method) {
            case "ping":
            case "initialize":
                // No specific capability required for these methods
                break;

            case 'tools/list':
            case 'tools/call':
                if (!$capabilities->toolsEnabled) {
                    throw McpServerException::methodNotFound($method, 'Tools are not enabled on this server.');
                }
                break;

            case 'resources/list':
            case 'resources/templates/list':
            case 'resources/read':
                if (!$capabilities->resourcesEnabled) {
                    throw McpServerException::methodNotFound($method, 'Resources are not enabled on this server.');
                }
                break;

            case 'resources/subscribe':
            case 'resources/unsubscribe':
                if (!$capabilities->resourcesEnabled) {
                    throw McpServerException::methodNotFound($method, 'Resources are not enabled on this server.');
                }
                if (!$capabilities->resourcesSubscribe) {
                    throw McpServerException::methodNotFound($method, 'Resources subscription is not enabled on this server.');
                }
                break;

            case 'prompts/list':
            case 'prompts/get':
                if (!$capabilities->promptsEnabled) {
                    throw McpServerException::methodNotFound($method, 'Prompts are not enabled on this server.');
                }
                break;

            case 'logging/setLevel':
                if (!$capabilities->loggingEnabled) {
                    throw McpServerException::methodNotFound($method, 'Logging is not enabled on this server.');
                }
                break;

            case 'completion/complete':
                if (!$capabilities->completionsEnabled) {
                    throw McpServerException::methodNotFound($method, 'Completions are not enabled on this server.');
                }
                break;

            default:
                break;
        }
    }

    private function canSendNotification(string $method): bool
    {
        $capabilities = $this->configuration->capabilities;

        $valid = true;

        switch ($method) {
            case 'notifications/message':
                if (!$capabilities->loggingEnabled) {
                    $this->logger->warning('Logging is not enabled on this server. Notifications/message will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/resources/updated":
            case "notifications/resources/list_changed":
                if (!$capabilities->resourcesListChanged) {
                    $this->logger->warning('Resources list changed notifications are not enabled on this server. Notifications/resources/list_changed will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/tools/list_changed":
                if (!$capabilities->toolsListChanged) {
                    $this->logger->warning('Tools list changed notifications are not enabled on this server. Notifications/tools/list_changed will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/prompts/list_changed":
                if (!$capabilities->promptsListChanged) {
                    $this->logger->warning('Prompts list changed notifications are not enabled on this server. Notifications/prompts/list_changed will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/cancelled":
                // Cancellation notifications are always allowed
                break;

            case "notifications/progress":
                // Progress notifications are always allowed
                break;

            default:
                break;
        }

        return $valid;
    }

    /**
     * Handles 'client_connected' event from the transport
     */
    public function handleClientConnected(string $sessionId): void
    {
        $this->logger->info('Client connected', ['sessionId' => $sessionId]);

        $this->sessionManager->createSession($sessionId);
    }

    /**
     * Handles 'client_disconnected' event from the transport
     */
    public function handleClientDisconnected(string $sessionId, ?string $reason = null): void
    {
        $this->logger->info('Client disconnected', ['clientId' => $sessionId, 'reason' => $reason ?? 'N/A']);

        $this->sessionManager->deleteSession($sessionId);
    }

    /**
     * Handle list changed event from registry
     */
    private function handleListChanged(string $listType): void
    {
        $listChangeUri = "mcp://changes/{$listType}";

        $subscribers = $this->subscriptionManager->getSubscribers($listChangeUri);
        if (empty($subscribers)) {
            return;
        }

        $method = "notifications/{$listType}/list_changed";

        if (!$this->canSendNotification($method)) {
            return;
        }

        $notification = Notification::make($method);

        foreach ($subscribers as $sessionId) {
            $this->sendNotification($sessionId, $notification);
        }

        $this->logger->debug("Sent list change notification", [
            'list_type' => $listType,
            'subscriber_count' => count($subscribers)
        ]);
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
