<?php

namespace PhpMcp\Server\State;

use PhpMcp\Server\JsonRpc\Message;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Manages client state and subscriptions using a PSR-16 cache.
 */
class TransportState
{
    private string $cachePrefix = 'mcp:';

    private int $cacheTtl = 3600; // Default TTL

    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger,
        ?string $cachePrefix = null,
        ?int $ttl = null
    ) {
        if ($cachePrefix !== null) {
            $cleanPrefix = preg_replace('/[^a-zA-Z0-9_.-]/', '', $cachePrefix); // Allow PSR-6 safe chars
            $this->cachePrefix = ! empty($cleanPrefix) ? rtrim($cleanPrefix, '_').'_' : '';
        }
        if ($ttl !== null) {
            $this->cacheTtl = $ttl;
        }
    }

    private function getCacheKey(string $key, ?string $clientId = null): string
    {
        return $clientId ? "{$this->cachePrefix}{$key}_{$clientId}" : "{$this->cachePrefix}{$key}";
    }

    // --- Initialization ---

    public function isInitialized(string $clientId): bool
    {
        return (bool) $this->cache->get($this->getCacheKey('initialized', $clientId), false);
    }

    public function markInitialized(string $clientId): void
    {
        try {
            $this->cache->set($this->getCacheKey('initialized', $clientId), true, $this->cacheTtl);
            $this->updateClientActivity($clientId);
            $this->logger->info('MCP: Client initialized.', ['client_id' => $clientId]);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to mark client as initialized in cache.', ['client_id' => $clientId, 'exception' => $e]);
        }
    }

    public function storeClientInfo(array $clientInfo, string $protocolVersion, string $clientId): void
    {
        try {
            $this->cache->set($this->getCacheKey('client_info', $clientId), $clientInfo, $this->cacheTtl);
            $this->cache->set($this->getCacheKey('protocol_version', $clientId), $protocolVersion, $this->cacheTtl);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to store client info in cache.', ['client_id' => $clientId, 'exception' => $e]);
        }
    }

    public function getClientInfo(string $clientId): ?array
    {
        return $this->cache->get($this->getCacheKey('client_info', $clientId));
    }

    public function getProtocolVersion(string $clientId): ?string
    {
        return $this->cache->get($this->getCacheKey('protocol_version', $clientId));
    }

    // --- Subscriptions ---

    public function addResourceSubscription(string $clientId, string $uri): void
    {
        try {
            $clientSubKey = $this->getCacheKey('client_subscriptions', $clientId);
            $resourceSubKey = $this->getCacheKey('resource_subscriptions', $uri);

            $clientSubscriptions = $this->cache->get($clientSubKey, []);
            $resourceSubscriptions = $this->cache->get($resourceSubKey, []);

            $clientSubscriptions[$uri] = true;
            $resourceSubscriptions[$clientId] = true;

            $this->cache->set($clientSubKey, $clientSubscriptions, $this->cacheTtl);
            $this->cache->set($resourceSubKey, $resourceSubscriptions, $this->cacheTtl);

            $this->logger->debug('MCP Client subscribed to resource.', ['client_id' => $clientId, 'uri' => $uri]);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to add resource subscription to cache.', ['client_id' => $clientId, 'uri' => $uri, 'exception' => $e]);
        }
    }

    public function removeResourceSubscription(string $clientId, string $uri): void
    {
        try {
            $clientSubKey = $this->getCacheKey('client_subscriptions', $clientId);
            $resourceSubKey = $this->getCacheKey('resource_subscriptions', $uri);

            $clientSubscriptions = $this->cache->get($clientSubKey, []);
            $resourceSubscriptions = $this->cache->get($resourceSubKey, []);

            if (isset($clientSubscriptions[$uri])) {
                unset($clientSubscriptions[$uri]);
                $this->cache->set($clientSubKey, $clientSubscriptions, $this->cacheTtl);
            }

            if (isset($resourceSubscriptions[$clientId])) {
                unset($resourceSubscriptions[$clientId]);
                $this->cache->set($resourceSubKey, $resourceSubscriptions, $this->cacheTtl);
            }

            $this->logger->debug('MCP Client unsubscribed from resource.', ['client_id' => $clientId, 'uri' => $uri]);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to remove resource subscription from cache.', ['client_id' => $clientId, 'uri' => $uri, 'exception' => $e]);
        }
    }

    public function removeAllResourceSubscriptions(string $clientId): void
    {
        try {
            $clientSubKey = $this->getCacheKey('client_subscriptions', $clientId);
            $clientSubscriptions = $this->cache->get($clientSubKey, []);

            if (empty($clientSubscriptions)) {
                return;
            }

            $uris = array_keys($clientSubscriptions);
            $resourceSubKeys = [];
            foreach ($uris as $uri) {
                $resourceSubKey = $this->getCacheKey('resource_subscriptions', $uri);
                $resourceSubscriptions = $this->cache->get($resourceSubKey, []);
                if (isset($resourceSubscriptions[$clientId])) {
                    unset($resourceSubscriptions[$clientId]);
                    // Only update if changes were made
                    if (empty($resourceSubscriptions)) {
                        $this->cache->delete($resourceSubKey);
                    } else {
                        $this->cache->set($resourceSubKey, $resourceSubscriptions, $this->cacheTtl);
                    }
                }
            }

            // Remove the client's subscription list
            $this->cache->delete($clientSubKey);

            $this->logger->debug('MCP: Client removed all resource subscriptions.', ['client_id' => $clientId, 'count' => count($uris)]);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to remove all resource subscriptions from cache.', ['client_id' => $clientId, 'exception' => $e]);
        }
    }

    /** @return array<string> */
    public function getResourceSubscribers(string $uri): array
    {
        $resourceSubscriptions = $this->cache->get($this->getCacheKey('resource_subscriptions', $uri), []);

        return array_keys($resourceSubscriptions);
    }

    public function isSubscribedToResource(string $clientId, string $uri): bool
    {
        $clientSubscriptions = $this->cache->get($this->getCacheKey('client_subscriptions', $clientId), []);

        return isset($clientSubscriptions[$uri]);
    }

    // --- Message Queue ---

    public function queueMessage(string $clientId, Message|array $message): void
    {
        try {
            $key = $this->getCacheKey('messages', $clientId);
            // Use locking or atomic operations if cache driver supports it to prevent race conditions
            $messages = $this->cache->get($key, []);

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
            }
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to queue message in cache.', ['client_id' => $clientId, 'exception' => $e]);
        }
    }

    public function queueMessageForAll(Message|array $message): void
    {
        $clients = $this->getActiveClients();
        foreach ($clients as $clientId) {
            $this->queueMessage($clientId, $message);
        }
    }

    /** @return array<array> */
    public function getQueuedMessages(string $clientId): array
    {
        try {
            $key = $this->getCacheKey('messages', $clientId);

            // Use atomic get-and-delete if cache driver supports it
            $messages = $this->cache->get($key, []);
            if (! empty($messages)) {
                $this->cache->delete($key);
            }

            return $messages;
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to get queued messages from cache.', ['client_id' => $clientId, 'exception' => $e->getMessage()]);

            return [];
        }
    }

    // --- Client Management ---

    public function cleanupClient(string $clientId, bool $removeFromActiveList = true): void
    {
        $this->removeAllResourceSubscriptions($clientId);

        try {
            if ($removeFromActiveList) {
                $activeClientsKey = $this->getCacheKey('active_clients');
                $activeClients = $this->cache->get($activeClientsKey, []);
                unset($activeClients[$clientId]);
                $this->cache->set($activeClientsKey, $activeClients, $this->cacheTtl);
            }

            // Delete other client-specific data
            $keysToDelete = [
                $this->getCacheKey('initialized', $clientId),
                $this->getCacheKey('client_info', $clientId),
                $this->getCacheKey('protocol_version', $clientId),
                $this->getCacheKey('messages', $clientId),
                $this->getCacheKey('client_subscriptions', $clientId),
            ];
            $this->cache->deleteMultiple($keysToDelete);

            $this->logger->info('MCP: Client removed.', ['client_id' => $clientId]);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to remove client data from cache.', ['client_id' => $clientId, 'exception' => $e]);
        }
    }

    public function updateClientActivity(string $clientId): void
    {
        try {
            $key = $this->getCacheKey('active_clients');
            $activeClients = $this->cache->get($key, []);
            $activeClients[$clientId] = time();
            $this->cache->set($key, $activeClients, $this->cacheTtl);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to update client activity in cache.', ['client_id' => $clientId, 'exception' => $e]);
        }
    }

    /** @return array<string> */
    public function getActiveClients(int $inactiveThreshold = 300): array
    {
        $activeClients = $this->cache->get($this->getCacheKey('active_clients'), []);
        $currentTime = time();
        $result = [];
        $clientsToCleanUp = [];

        foreach ($activeClients as $clientId => $lastSeen) {
            if ($currentTime - $lastSeen < $inactiveThreshold) {
                $result[] = $clientId;
            } else {
                $this->logger->info('MCP: Client considered inactive, removing from active list.', ['client_id' => $clientId, 'last_seen' => $lastSeen]);
                $clientsToCleanUp[] = $clientId;
                unset($activeClients[$clientId]);
            }
        }

        if (! empty($clientsToCleanUp)) {
            try {
                $this->cache->set($this->getCacheKey('active_clients'), $activeClients, $this->cacheTtl);

                foreach ($clientsToCleanUp as $clientId) {
                    $this->cleanupClient($clientId, false);
                }
            } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
                $this->logger->error('Failed to save cleaned active client list to cache.', ['exception' => $e]);
            }
        }

        return $result;
    }

    /**
     * Retrieves the last activity timestamp for a specific client.
     *
     * @return float|null The Unix timestamp (with microseconds) of the last activity, or null if unknown.
     */
    public function getLastActivityTime(string $clientId): ?float
    {
        try {
            $activeClientsKey = $this->getCacheKey('active_clients');
            $activeClients = $this->cache->get($activeClientsKey, []);

            return $activeClients[$clientId] ?? null;
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('Failed to get client activity time from cache.', ['client_id' => $clientId, 'exception' => $e]);

            return null;
        }
    }
}
