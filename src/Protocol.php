<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use PhpMcp\Schema\Constants;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Schema\JsonRpc\BatchResponse;
use PhpMcp\Schema\JsonRpc\Error;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Response;
use PhpMcp\Schema\Notification\PromptListChangedNotification;
use PhpMcp\Schema\Notification\ResourceListChangedNotification;
use PhpMcp\Schema\Notification\ResourceUpdatedNotification;
use PhpMcp\Schema\Notification\RootsListChangedNotification;
use PhpMcp\Schema\Notification\ToolListChangedNotification;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Session\SubscriptionManager;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

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
        protected ?Dispatcher $dispatcher = null,
        protected ?SubscriptionManager $subscriptionManager = null,
    ) {
        $this->logger = $this->configuration->logger;
        $this->subscriptionManager ??= new SubscriptionManager($this->logger);
        $this->dispatcher ??= new Dispatcher($this->configuration, $this->registry, $this->subscriptionManager);

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

        if ($session === null) {
            $error = Error::forInvalidRequest('Invalid or expired session. Please re-initialize the session.', $message->id);
            $context['status_code'] = 404;

            $this->transport->sendMessage($error, $sessionId, $context)
                ->then(function () use ($sessionId, $error, $context) {
                    $this->logger->debug('Response sent.', ['sessionId' => $sessionId, 'payload' => $error, 'context' => $context]);
                })
                ->catch(function (Throwable $e) use ($sessionId, $error, $context) {
                    $this->logger->error('Failed to send response.', ['sessionId' => $sessionId, 'error' => $e->getMessage()]);
                });

            return;
        }

        if ($context['stateless'] ?? false) {
            $session->set('initialized', true);
            $session->set('protocol_version', self::LATEST_PROTOCOL_VERSION);
            $session->set('client_info', ['name' => 'stateless-client', 'version' => '1.0.0']);
        }

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
    private function processBatchRequest(BatchRequest $batch, SessionInterface $session): ?BatchResponse
    {
        $items = [];

        foreach ($batch->getNotifications() as $notification) {
            $this->processNotification($notification, $session);
        }

        foreach ($batch->getRequests() as $request) {
            $items[] = $this->processRequest($request, $session);
        }

        return empty($items) ? null : new BatchResponse($items);
    }

    /**
     * Process a request message
     */
    private function processRequest(Request $request, SessionInterface $session): Response|Error
    {
        try {
            if ($request->method !== 'initialize') {
                $this->assertSessionInitialized($session);
            }

            $this->assertRequestCapability($request->method);

            $result = $this->dispatcher->handleRequest($request, $session);

            return Response::make($request->id, $result);
        } catch (McpServerException $e) {
            $this->logger->debug('MCP Processor caught McpServerException', ['method' => $request->method, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => $e->getData()]);

            return $e->toJsonRpcError($request->id);
        } catch (Throwable $e) {
            $this->logger->error('MCP Processor caught unexpected error', [
                'method' => $request->method,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new Error(
                jsonrpc: '2.0',
                id: $request->id,
                code: Constants::INTERNAL_ERROR,
                message: 'Internal error processing method ' . $request->method,
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
            $this->dispatcher->handleNotification($notification, $session);
        } catch (Throwable $e) {
            $this->logger->error('Error while processing notification', ['method' => $method, 'exception' => $e->getMessage()]);
            return;
        }
    }

    /**
     * Send a notification to a session
     */
    public function sendNotification(Notification $notification, string $sessionId): PromiseInterface
    {
        if ($this->transport === null) {
            $this->logger->error('Cannot send notification, transport not bound', [
                'sessionId' => $sessionId,
                'method' => $notification->method
            ]);
            return reject(new McpServerException('Transport not bound'));
        }

        return $this->transport->sendMessage($notification, $sessionId, [])
            ->then(function () {
                return resolve(null);
            })
            ->catch(function (Throwable $e) {
                return reject(new McpServerException('Failed to send notification: ' . $e->getMessage(), previous: $e));
            });
    }

    /**
     * Notify subscribers about resource content change
     */
    public function notifyResourceUpdated(string $uri): void
    {
        $subscribers = $this->subscriptionManager->getSubscribers($uri);

        if (empty($subscribers)) {
            return;
        }

        $notification = ResourceUpdatedNotification::make($uri);

        foreach ($subscribers as $sessionId) {
            $this->sendNotification($notification, $sessionId);
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
                if (!$capabilities->tools) {
                    throw McpServerException::methodNotFound($method, 'Tools are not enabled on this server.');
                }
                break;

            case 'resources/list':
            case 'resources/templates/list':
            case 'resources/read':
                if (!$capabilities->resources) {
                    throw McpServerException::methodNotFound($method, 'Resources are not enabled on this server.');
                }
                break;

            case 'resources/subscribe':
            case 'resources/unsubscribe':
                if (!$capabilities->resources) {
                    throw McpServerException::methodNotFound($method, 'Resources are not enabled on this server.');
                }
                if (!$capabilities->resourcesSubscribe) {
                    throw McpServerException::methodNotFound($method, 'Resources subscription is not enabled on this server.');
                }
                break;

            case 'prompts/list':
            case 'prompts/get':
                if (!$capabilities->prompts) {
                    throw McpServerException::methodNotFound($method, 'Prompts are not enabled on this server.');
                }
                break;

            case 'logging/setLevel':
                if (!$capabilities->logging) {
                    throw McpServerException::methodNotFound($method, 'Logging is not enabled on this server.');
                }
                break;

            case 'completion/complete':
                if (!$capabilities->completions) {
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
                if (!$capabilities->logging) {
                    $this->logger->warning('Logging is not enabled on this server. Notifications/message will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/resources/updated":
            case "notifications/resources/list_changed":
                if (!$capabilities->resources || !$capabilities->resourcesListChanged) {
                    $this->logger->warning('Resources list changed notifications are not enabled on this server. Notifications/resources/list_changed will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/tools/list_changed":
                if (!$capabilities->tools || !$capabilities->toolsListChanged) {
                    $this->logger->warning('Tools list changed notifications are not enabled on this server. Notifications/tools/list_changed will not be sent.');
                    $valid = false;
                }
                break;

            case "notifications/prompts/list_changed":
                if (!$capabilities->prompts || !$capabilities->promptsListChanged) {
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
    public function handleListChanged(string $listType): void
    {
        $listChangeUri = "mcp://changes/{$listType}";

        $subscribers = $this->subscriptionManager->getSubscribers($listChangeUri);
        if (empty($subscribers)) {
            return;
        }

        $notification = match ($listType) {
            'resources' => ResourceListChangedNotification::make(),
            'tools' => ToolListChangedNotification::make(),
            'prompts' => PromptListChangedNotification::make(),
            'roots' => RootsListChangedNotification::make(),
            default => throw new \InvalidArgumentException("Invalid list type: {$listType}"),
        };

        if (!$this->canSendNotification($notification->method)) {
            return;
        }

        foreach ($subscribers as $sessionId) {
            $this->sendNotification($notification, $sessionId);
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
