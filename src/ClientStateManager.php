<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use PhpMcp\Server\JsonRpc\Message;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Throwable;

/**
 * Manages client-specific runtime state (initialization, subscriptions, message queue)
 * using a PSR-16 cache.
 */
class ClientStateManager
{
    private ?CacheInterface $cache;

    private LoggerInterface $logger;

    private string $cachePrefix;

    private int $cacheTtl;

    public function __construct(
        LoggerInterface $logger,
        ?CacheInterface $cache = null,
        string $cachePrefix = 'mcp_state_',
        int $cacheTtl = 3600
    ) {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->cachePrefix = $cachePrefix;
        $this->cacheTtl = max(60, $cacheTtl); // Minimum TTL of 60 seconds
    }

    private function getCacheKey(string $key, ?string $clientId = null): string
    {
        return $clientId ? "{$this->cachePrefix}{$key}_{$clientId}" : "{$this->cachePrefix}{$key}";
    }

    // --- Initialization ---

    public function isInitialized(string $clientId): bool
    {
        if (! $this->cache) {
            return false;
        }

        return (bool) $this->cache->get($this->getCacheKey('initialized', $clientId), false);
    }

    public function markInitialized(string $clientId): void
    {
        if (! $this->cache) {
            $this->logger->warning('Cannot mark client as initialized, cache not available.', ['clientId' => $clientId]);

            return;
        }
        try {
            $this->cache->set($this->getCacheKey('initialized', $clientId), true, $this->cacheTtl);
            $this->updateClientActivity($clientId);
            $this->logger->info('MCP State: Client marked initialized.', ['client_id' => $clientId]);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to mark client as initialized in cache (invalid key).', ['clientId' => $clientId, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to mark client as initialized in cache.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    public function storeClientInfo(array $clientInfo, string $protocolVersion, string $clientId): void
    {
        if (! $this->cache) {
            return;
        }
        try {
            $this->cache->set($this->getCacheKey('client_info', $clientId), $clientInfo, $this->cacheTtl);
            $this->cache->set($this->getCacheKey('protocol_version', $clientId), $protocolVersion, $this->cacheTtl);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to store client info in cache (invalid key).', ['clientId' => $clientId, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to store client info in cache.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    public function getClientInfo(string $clientId): ?array
    {
        if (! $this->cache) {
            return null;
        }
        try {
            return $this->cache->get($this->getCacheKey('client_info', $clientId));
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to get client info from cache (invalid key).', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to get client info from cache.', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        }
    }

    public function getProtocolVersion(string $clientId): ?string
    {
        if (! $this->cache) {
            return null;
        }
        try {
            return $this->cache->get($this->getCacheKey('protocol_version', $clientId));
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to get protocol version from cache (invalid key).', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to get protocol version from cache.', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        }
    }

    // --- Subscriptions (methods need cache check) ---

    public function addResourceSubscription(string $clientId, string $uri): void
    {
        if (! $this->cache) {
            $this->logger->warning('Cannot add resource subscription, cache not available.', ['clientId' => $clientId, 'uri' => $uri]);

            return;
        }
        try {
            $clientSubKey = $this->getCacheKey('client_subscriptions', $clientId);
            $resourceSubKey = $this->getCacheKey('resource_subscriptions', $uri);

            // It's safer to get existing, modify, then set, though slightly less atomic
            $clientSubscriptions = $this->cache->get($clientSubKey, []);
            $resourceSubscriptions = $this->cache->get($resourceSubKey, []);

            $clientSubscriptions = is_array($clientSubscriptions) ? $clientSubscriptions : [];
            $resourceSubscriptions = is_array($resourceSubscriptions) ? $resourceSubscriptions : [];

            $clientSubscriptions[$uri] = true;
            $resourceSubscriptions[$clientId] = true;

            $this->cache->set($clientSubKey, $clientSubscriptions, $this->cacheTtl);
            $this->cache->set($resourceSubKey, $resourceSubscriptions, $this->cacheTtl);

            $this->logger->debug('MCP State: Client subscribed to resource.', ['clientId' => $clientId, 'uri' => $uri]);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to add resource subscription (invalid key).', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to add resource subscription.', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);
        }
    }

    public function removeResourceSubscription(string $clientId, string $uri): void
    {
        if (! $this->cache) {
            return;
        }
        try {
            $clientSubKey = $this->getCacheKey('client_subscriptions', $clientId);
            $resourceSubKey = $this->getCacheKey('resource_subscriptions', $uri);

            $clientSubscriptions = $this->cache->get($clientSubKey, []);
            $resourceSubscriptions = $this->cache->get($resourceSubKey, []);
            $clientSubscriptions = is_array($clientSubscriptions) ? $clientSubscriptions : [];
            $resourceSubscriptions = is_array($resourceSubscriptions) ? $resourceSubscriptions : [];

            $clientChanged = false;
            if (isset($clientSubscriptions[$uri])) {
                unset($clientSubscriptions[$uri]);
                $clientChanged = true;
            }

            $resourceChanged = false;
            if (isset($resourceSubscriptions[$clientId])) {
                unset($resourceSubscriptions[$clientId]);
                $resourceChanged = true;
            }

            if ($clientChanged) {
                if (empty($clientSubscriptions)) {
                    $this->cache->delete($clientSubKey);
                } else {
                    $this->cache->set($clientSubKey, $clientSubscriptions, $this->cacheTtl);
                }
            }
            if ($resourceChanged) {
                if (empty($resourceSubscriptions)) {
                    $this->cache->delete($resourceSubKey);
                } else {
                    $this->cache->set($resourceSubKey, $resourceSubscriptions, $this->cacheTtl);
                }
            }

            if ($clientChanged || $resourceChanged) {
                $this->logger->debug('MCP State: Client unsubscribed from resource.', ['clientId' => $clientId, 'uri' => $uri]);
            }

        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to remove resource subscription (invalid key).', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to remove resource subscription.', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);
        }
    }

    public function removeAllResourceSubscriptions(string $clientId): void
    {
        if (! $this->cache) {
            return;
        }
        try {
            $clientSubKey = $this->getCacheKey('client_subscriptions', $clientId);
            $clientSubscriptions = $this->cache->get($clientSubKey, []);
            $clientSubscriptions = is_array($clientSubscriptions) ? $clientSubscriptions : [];

            if (empty($clientSubscriptions)) {
                return;
            }

            $uris = array_keys($clientSubscriptions);
            $keysToDeleteFromResources = [];
            $keysToUpdateResources = [];
            $updatedResourceSubs = [];

            foreach ($uris as $uri) {
                $resourceSubKey = $this->getCacheKey('resource_subscriptions', $uri);
                $resourceSubscriptions = $this->cache->get($resourceSubKey, []);
                $resourceSubscriptions = is_array($resourceSubscriptions) ? $resourceSubscriptions : [];

                if (isset($resourceSubscriptions[$clientId])) {
                    unset($resourceSubscriptions[$clientId]);
                    if (empty($resourceSubscriptions)) {
                        $keysToDeleteFromResources[] = $resourceSubKey;
                    } else {
                        $keysToUpdateResources[] = $resourceSubKey;
                        $updatedResourceSubs[$resourceSubKey] = $resourceSubscriptions;
                    }
                }
            }

            // Perform cache operations
            if (! empty($keysToDeleteFromResources)) {
                $this->cache->deleteMultiple($keysToDeleteFromResources);
            }
            foreach ($keysToUpdateResources as $key) {
                $this->cache->set($key, $updatedResourceSubs[$key], $this->cacheTtl);
            }
            $this->cache->delete($clientSubKey); // Remove client's master list

            $this->logger->debug('MCP State: Client removed all resource subscriptions.', ['clientId' => $clientId, 'count' => count($uris)]);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to remove all resource subscriptions (invalid key).', ['clientId' => $clientId, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to remove all resource subscriptions.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    /** @return array<string> */
    public function getResourceSubscribers(string $uri): array
    {
        if (! $this->cache) {
            return [];
        }
        try {
            $resourceSubscriptions = $this->cache->get($this->getCacheKey('resource_subscriptions', $uri), []);

            return is_array($resourceSubscriptions) ? array_keys($resourceSubscriptions) : [];
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to get resource subscribers (invalid key).', ['uri' => $uri, 'exception' => $e]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to get resource subscribers.', ['uri' => $uri, 'exception' => $e]);

            return [];
        }
    }

    public function isSubscribedToResource(string $clientId, string $uri): bool
    {
        if (! $this->cache) {
            return false;
        }
        try {
            $clientSubscriptions = $this->cache->get($this->getCacheKey('client_subscriptions', $clientId), []);

            return is_array($clientSubscriptions) && isset($clientSubscriptions[$uri]);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to check resource subscription (invalid key).', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);

            return false;
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to check resource subscription.', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);

            return false;
        }
    }

    // --- Message Queue (methods need cache check) ---

    public function queueMessage(string $clientId, Message|array $message): void
    {
        if (! $this->cache) {
            $this->logger->warning('Cannot queue message, cache not available.', ['clientId' => $clientId]);

            return;
        }
        try {
            $key = $this->getCacheKey('messages', $clientId);
            $messages = $this->cache->get($key, []);
            $messages = is_array($messages) ? $messages : [];

            $newMessages = [];
            if (is_array($message)) {
                foreach ($message as $singleMessage) {
                    if ($singleMessage instanceof Message) {
                        $newMessages[] = $singleMessage->toArray();
                    }
                }
            } elseif ($message instanceof Message) {
                $newMessages[] = $message->toArray();
            }

            if (! empty($newMessages)) {
                $this->cache->set($key, array_merge($messages, $newMessages), $this->cacheTtl);
                $this->logger->debug('MCP State: Queued message(s).', ['clientId' => $clientId, 'count' => count($newMessages)]);
            }
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to queue message (invalid key).', ['clientId' => $clientId, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to queue message.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    public function queueMessageForAll(Message|array $message): void
    {
        if (! $this->cache) {
            $this->logger->warning('Cannot queue message for all, cache not available.');

            return;
        }
        $clients = $this->getActiveClients(); // getActiveClients handles cache check internally
        if (empty($clients)) {
            $this->logger->debug('MCP State: No active clients found to queue message for.');

            return;
        }
        $this->logger->debug('MCP State: Queuing message for all active clients.', ['count' => count($clients)]);
        foreach ($clients as $clientId) {
            $this->queueMessage($clientId, $message);
        }
    }

    /** @return array<array> */
    public function getQueuedMessages(string $clientId): array
    {
        if (! $this->cache) {
            return [];
        }
        try {
            $key = $this->getCacheKey('messages', $clientId);
            $messages = $this->cache->get($key, []);
            $messages = is_array($messages) ? $messages : [];

            if (! empty($messages)) {
                $this->cache->delete($key);
                $this->logger->debug('MCP State: Retrieved and cleared queued messages.', ['clientId' => $clientId, 'count' => count($messages)]);
            }

            return $messages;
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to get/delete queued messages (invalid key).', ['clientId' => $clientId, 'exception' => $e]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to get/delete queued messages.', ['clientId' => $clientId, 'exception' => $e]);

            return [];
        }
    }

    // --- Client Management ---

    public function cleanupClient(string $clientId, bool $removeFromActiveList = true): void
    {
        $this->removeAllResourceSubscriptions($clientId);

        if (! $this->cache) {
            $this->logger->warning('Cannot perform full client cleanup, cache not available.', ['clientId' => $clientId]);

            return;
        }

        try {
            if ($removeFromActiveList) {
                $activeClientsKey = $this->getCacheKey('active_clients');
                $activeClients = $this->cache->get($activeClientsKey, []);
                $activeClients = is_array($activeClients) ? $activeClients : [];
                if (isset($activeClients[$clientId])) {
                    unset($activeClients[$clientId]);
                    $this->cache->set($activeClientsKey, $activeClients, $this->cacheTtl);
                }
            }

            // Delete other client-specific data
            $keysToDelete = [
                $this->getCacheKey('initialized', $clientId),
                $this->getCacheKey('client_info', $clientId),
                $this->getCacheKey('protocol_version', $clientId),
                $this->getCacheKey('messages', $clientId),
                // client_subscriptions key already deleted by removeAllResourceSubscriptions if needed
            ];
            $this->cache->deleteMultiple($keysToDelete);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to remove client data from cache (invalid key).', ['clientId' => $clientId, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to remove client data from cache.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    public function updateClientActivity(string $clientId): void
    {
        if (! $this->cache) {
            return;
        }
        try {
            $key = $this->getCacheKey('active_clients');
            $activeClients = $this->cache->get($key, []);
            $activeClients = is_array($activeClients) ? $activeClients : [];
            $activeClients[$clientId] = time(); // Using integer timestamp
            $this->cache->set($key, $activeClients, $this->cacheTtl);
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to update client activity (invalid key).', ['clientId' => $clientId, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to update client activity.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    /** @return array<string> Active client IDs */
    public function getActiveClients(int $inactiveThreshold = 300): array
    {
        if (! $this->cache) {
            return [];
        }
        try {
            $activeClientsKey = $this->getCacheKey('active_clients');
            $activeClients = $this->cache->get($activeClientsKey, []);
            $activeClients = is_array($activeClients) ? $activeClients : [];

            $currentTime = time();
            $result = [];
            $clientsToCleanUp = [];
            $listChanged = false;

            foreach ($activeClients as $clientId => $lastSeen) {
                if (! is_int($lastSeen)) { // Data sanity check
                    $this->logger->warning('Invalid timestamp found in active clients list, removing.', ['clientId' => $clientId, 'value' => $lastSeen]);
                    $clientsToCleanUp[] = $clientId;
                    $listChanged = true;

                    continue;
                }
                if ($currentTime - $lastSeen < $inactiveThreshold) {
                    $result[] = $clientId;
                } else {
                    $this->logger->info('MCP State: Client considered inactive, scheduling cleanup.', ['clientId' => $clientId, 'last_seen' => $lastSeen]);
                    $clientsToCleanUp[] = $clientId;
                    $listChanged = true;
                }
            }

            if ($listChanged) {
                $updatedActiveClients = $activeClients;
                foreach ($clientsToCleanUp as $idToClean) {
                    unset($updatedActiveClients[$idToClean]);
                }
                $this->cache->set($activeClientsKey, $updatedActiveClients, $this->cacheTtl);

                // Perform cleanup for inactive clients (without removing from list again)
                foreach ($clientsToCleanUp as $idToClean) {
                    $this->cleanupClient($idToClean, false);
                }
            }

            return $result;
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to get active clients (invalid key).', ['exception' => $e]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to get active clients.', ['exception' => $e]);

            return [];
        }
    }

    /** Retrieves the last activity timestamp for a specific client. */
    public function getLastActivityTime(string $clientId): ?int // Return int (Unix timestamp)
    {
        if (! $this->cache) {
            return null;
        }
        try {
            $activeClientsKey = $this->getCacheKey('active_clients');
            $activeClients = $this->cache->get($activeClientsKey, []);
            $activeClients = is_array($activeClients) ? $activeClients : [];

            $lastSeen = $activeClients[$clientId] ?? null;

            return is_int($lastSeen) ? $lastSeen : null;

        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP State: Failed to get client activity time (invalid key).', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        } catch (Throwable $e) {
            $this->logger->error('MCP State: Failed to get client activity time.', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        }
    }
}
