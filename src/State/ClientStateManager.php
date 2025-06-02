<?php

declare(strict_types=1);

namespace PhpMcp\Server\State;

use PhpMcp\Server\Defaults\ArrayCache;
use PhpMcp\Server\JsonRpc\Message;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Manages client-specific runtime state using a PSR-16 cache.
 *
 * Defaults to an in-memory ArrayCache if no cache is provided.
 */
class ClientStateManager
{
    protected string $cachePrefix;

    public const GLOBAL_RESOURCE_SUBSCRIBERS_KEY_PREFIX = 'mcp_res_subs_';

    public const GLOBAL_ACTIVE_CLIENTS_KEY = 'mcp_active_clients';

    public function __construct(
        protected LoggerInterface $logger,
        protected ?CacheInterface $cache = null,
        string $clientDataPrefix = 'mcp_client_data_',
        protected int $cacheTtl = 3600
    ) {
        $this->cachePrefix = $clientDataPrefix;
        $this->cacheTtl = max(60, $cacheTtl);

        $this->cache ??= new ArrayCache();
    }

    private function getClientStateCacheKey(string $clientId): string
    {
        return $this->cachePrefix . $clientId;
    }

    private function getResourceSubscribersCacheKey(string $uri): string
    {
        return self::GLOBAL_RESOURCE_SUBSCRIBERS_KEY_PREFIX . sha1($uri);
    }

    private function getActiveClientsCacheKey(): string
    {
        return $this->cachePrefix . self::GLOBAL_ACTIVE_CLIENTS_KEY;
    }

    /**
     * Fetches or creates a ClientState object for a client.
     */
    private function getClientState(string $clientId, bool $createIfNotFound = false): ?ClientState
    {
        $key = $this->getClientStateCacheKey($clientId);

        try {
            $state = $this->cache->get($key);
            if ($state instanceof ClientState) {
                return $state;
            }

            if ($state !== null) {
                $this->logger->warning('Invalid data type found in cache for client state, deleting.', ['clientId' => $clientId, 'key' => $key]);
                $this->cache->delete($key);
            }

            if ($createIfNotFound) {
                return new ClientState($clientId);
            }
        } catch (Throwable $e) {
            $this->logger->error('Error fetching client state from cache.', ['clientId' => $clientId, 'key' => $key, 'exception' => $e]);
        }

        return null;
    }

    /**
     * Saves a ClientState object to the cache.
     */
    private function saveClientState(string $clientId, ClientState $state): bool
    {
        $key = $this->getClientStateCacheKey($clientId);

        try {
            $state->lastActivityTimestamp = time();

            return $this->cache->set($key, $state, $this->cacheTtl);
        } catch (Throwable $e) {
            $this->logger->error('Error saving client state to cache.', ['clientId' => $clientId, 'key' => $key, 'exception' => $e]);

            return false;
        }
    }

    /**
     * Checks if a client has been initialized.
     */
    public function isInitialized(string $clientId): bool
    {
        $state = $this->getClientState($clientId);

        return $state !== null && $state->isInitialized;
    }

    /**
     * Marks a client as initialized.
     */
    public function markInitialized(string $clientId): void
    {
        $state = $this->getClientState($clientId, true);

        if ($state) {
            $state->isInitialized = true;

            if ($this->saveClientState($clientId, $state)) {
                $this->updateGlobalActiveClientTimestamp($clientId);
            }
        } else {
            $this->logger->error('Failed to get/create state to mark client as initialized.', ['clientId' => $clientId]);
        }
    }

    /**
     * Stores client information.
     */
    public function storeClientInfo(array $clientInfo, string $protocolVersion, string $clientId): void
    {
        $state = $this->getClientState($clientId, true);

        if ($state) {
            $state->clientInfo = $clientInfo;
            $state->protocolVersion = $protocolVersion;
            $this->saveClientState($clientId, $state);
        }
    }

    /**
     * Gets client information.
     */
    public function getClientInfo(string $clientId): ?array
    {
        return $this->getClientState($clientId)?->clientInfo;
    }

    /**
     * Gets the protocol version for a client.
     */
    public function getProtocolVersion(string $clientId): ?string
    {
        return $this->getClientState($clientId)?->protocolVersion;
    }

    /**
     * Adds a resource subscription for a client.
     */
    public function addResourceSubscription(string $clientId, string $uri): void
    {
        $clientState = $this->getClientState($clientId, true);
        if (! $clientState) {
            $this->logger->error('Failed to get/create client state for subscription.', ['clientId' => $clientId, 'uri' => $uri]);

            return;
        }

        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);

        try {
            $clientState->addSubscription($uri);
            $this->saveClientState($clientId, $clientState);

            $subscribers = $this->cache->get($resourceSubKey, []);
            $subscribers = is_array($subscribers) ? $subscribers : [];
            $subscribers[$clientId] = true;
            $this->cache->set($resourceSubKey, $subscribers, $this->cacheTtl);

            $this->logger->debug('Client subscribed to resource.', ['clientId' => $clientId, 'uri' => $uri]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to add resource subscription.', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);
        }
    }

    /**
     * Removes a resource subscription for a client.
     */
    public function removeResourceSubscription(string $clientId, string $uri): void
    {
        $clientState = $this->getClientState($clientId);
        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);

        try {
            if ($clientState) {
                $clientState->removeSubscription($uri);
                $this->saveClientState($clientId, $clientState);
            }

            $subscribers = $this->cache->get($resourceSubKey, []);
            $subscribers = is_array($subscribers) ? $subscribers : [];
            $changed = false;

            if (isset($subscribers[$clientId])) {
                unset($subscribers[$clientId]);
                $changed = true;
            }

            if ($changed) {
                if (empty($subscribers)) {
                    $this->cache->delete($resourceSubKey);
                } else {
                    $this->cache->set($resourceSubKey, $subscribers, $this->cacheTtl);
                }
                $this->logger->debug('Client unsubscribed from resource.', ['clientId' => $clientId, 'uri' => $uri]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to remove resource subscription.', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);
        }
    }

    /**
     * Removes all resource subscriptions for a client.
     */
    public function removeAllResourceSubscriptions(string $clientId): void
    {
        $clientState = $this->getClientState($clientId);
        if (! $clientState || empty($clientState->subscriptions)) {
            return;
        }

        $urisClientWasSubscribedTo = array_keys($clientState->subscriptions);

        try {
            $clientState->clearSubscriptions();
            $this->saveClientState($clientId, $clientState);

            foreach ($urisClientWasSubscribedTo as $uri) {
                $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);
                $subscribers = $this->cache->get($resourceSubKey, []);
                $subscribers = is_array($subscribers) ? $subscribers : [];
                if (isset($subscribers[$clientId])) {
                    unset($subscribers[$clientId]);
                    if (empty($subscribers)) {
                        $this->cache->delete($resourceSubKey);
                    } else {
                        $this->cache->set($resourceSubKey, $subscribers, $this->cacheTtl);
                    }
                }
            }
            $this->logger->debug('Client removed all resource subscriptions.', ['clientId' => $clientId, 'count' => count($urisClientWasSubscribedTo)]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to remove all resource subscriptions.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    /**
     * Gets the client IDs subscribed to a resource.
     *
     * @return string[] Client IDs subscribed to the URI
     */
    public function getResourceSubscribers(string $uri): array
    {
        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);
        try {
            $subscribers = $this->cache->get($resourceSubKey, []);

            return is_array($subscribers) ? array_keys($subscribers) : [];
        } catch (Throwable $e) {
            $this->logger->error('Failed to get resource subscribers.', ['uri' => $uri, 'exception' => $e]);

            return [];
        }
    }

    /**
     * Checks if a client is subscribed to a resource.
     */
    public function isSubscribedToResource(string $clientId, string $uri): bool
    {
        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);

        try {
            $subscribers = $this->cache->get($resourceSubKey, []);

            return is_array($subscribers) && isset($subscribers[$clientId]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to check resource subscription.', ['clientId' => $clientId, 'uri' => $uri, 'exception' => $e]);

            return false;
        }
    }

    /**
     * Queues a message for a client.
     */
    public function queueMessage(string $clientId, Message|array $message): void
    {
        $state = $this->getClientState($clientId, true);
        if (! $state) {
            return;
        }

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
            foreach ($newMessages as $msgData) {
                $state->addMessageToQueue($msgData);
            }

            $this->saveClientState($clientId, $state);
        }
    }

    /**
     * Queues a message for all active clients.
     */
    public function queueMessageForAll(Message|array $message): void
    {
        $clients = $this->getActiveClients();

        foreach ($clients as $clientId) {
            $this->queueMessage($clientId, $message);
        }
    }

    /**
     * Gets the queued messages for a client.
     *
     * @return array<array> Queued messages
     */
    public function getQueuedMessages(string $clientId): array
    {
        $state = $this->getClientState($clientId);
        if (! $state) {
            return [];
        }

        $messages = $state->consumeMessageQueue();
        if (! empty($messages)) {
            $this->saveClientState($clientId, $state);
        }

        return $messages;
    }

    /**
     * Sets the requested log level for a specific client.
     * This preference is stored in the client's state.
     *
     * @param  string  $clientId  The ID of the client.
     * @param  string  $level  The PSR-3 log level string (e.g., 'debug', 'info').
     */
    public function setClientRequestedLogLevel(string $clientId, string $level): void
    {
        $state = $this->getClientState($clientId, true);
        if (! $state) {
            $this->logger->error('Failed to get/create state to set log level.', ['clientId' => $clientId, 'level' => $level]);

            return;
        }

        $state->requestedLogLevel = strtolower($level);
        $this->saveClientState($clientId, $state);
    }

    /**
     * Gets the client-requested log level.
     * Returns null if the client hasn't set a specific level, implying server default should be used.
     *
     * @param  string  $clientId  The ID of the client.
     * @return string|null The PSR-3 log level string or null.
     */
    public function getClientRequestedLogLevel(string $clientId): ?string
    {
        return $this->getClientState($clientId)?->requestedLogLevel;
    }

    /**
     * Cleans up a client's state.
     */
    public function cleanupClient(string $clientId, bool $removeFromActiveList = true): void
    {
        $this->removeAllResourceSubscriptions($clientId);

        $clientStateKey = $this->getClientStateCacheKey($clientId);
        try {
            $this->cache->delete($clientStateKey);
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete client state object.', ['clientId' => $clientId, 'key' => $clientStateKey, 'exception' => $e]);
        }

        if ($removeFromActiveList) {
            $activeClientsKey = $this->getActiveClientsCacheKey();
            try {
                $activeClients = $this->cache->get($activeClientsKey, []);
                $activeClients = is_array($activeClients) ? $activeClients : [];
                if (isset($activeClients[$clientId])) {
                    unset($activeClients[$clientId]);
                    $this->cache->set($activeClientsKey, $activeClients, $this->cacheTtl);
                }
            } catch (Throwable $e) {
                $this->logger->error('Failed to update global active clients list during cleanup.', ['clientId' => $clientId, 'exception' => $e]);
            }
        }
        $this->logger->info('Client state cleaned up.', ['client_id' => $clientId]);
    }

    /**
     * Updates the global active client list with current timestamp
     */
    private function updateGlobalActiveClientTimestamp(string $clientId): void
    {
        try {
            $key = $this->getActiveClientsCacheKey();
            $activeClients = $this->cache->get($key, []);
            $activeClients = is_array($activeClients) ? $activeClients : [];
            $activeClients[$clientId] = time();
            $this->cache->set($key, $activeClients, $this->cacheTtl);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update global active client timestamp.', ['clientId' => $clientId, 'exception' => $e]);
        }
    }

    /**
     * Updates client's own lastActivityTimestamp AND the global list
     */
    public function updateClientActivity(string $clientId): void
    {
        $state = $this->getClientState($clientId, true);
        if ($state) {
            if (! $this->saveClientState($clientId, $state)) {
                $this->logger->warning('Failed to save client state after updating activity.', ['clientId' => $clientId]);
            }
        }
        $this->updateGlobalActiveClientTimestamp($clientId);
    }

    /**
     * Gets the active clients from the global active list.
     *
     * @return string[] Client IDs from the global active list
     */
    public function getActiveClients(int $inactiveThreshold = 300): array
    {
        try {
            $activeClientsKey = $this->getActiveClientsCacheKey();
            $activeClientsData = $this->cache->get($activeClientsKey, []);
            $activeClientsData = is_array($activeClientsData) ? $activeClientsData : [];

            $currentTime = time();
            $validActiveClientIds = [];
            $clientsToCleanUp = [];
            $listNeedsUpdateInCache = false;

            foreach ($activeClientsData as $id => $lastSeen) {
                if (! is_string($id) || ! is_int($lastSeen)) { // Sanity check entry
                    $clientsToCleanUp[] = $id;
                    $listNeedsUpdateInCache = true;

                    continue;
                }
                if ($currentTime - $lastSeen < $inactiveThreshold) {
                    $validActiveClientIds[] = $id;
                } else {
                    $clientsToCleanUp[] = $id;
                    $listNeedsUpdateInCache = true;
                }
            }

            if ($listNeedsUpdateInCache) {
                $updatedList = $activeClientsData;
                foreach ($clientsToCleanUp as $idToClean) {
                    unset($updatedList[$idToClean]);
                }
                $this->cache->set($activeClientsKey, $updatedList, $this->cacheTtl);

                foreach ($clientsToCleanUp as $idToClean) {
                    $this->cleanupClient($idToClean, false); // false: already handled active list
                }
            }

            return $validActiveClientIds;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get active clients.', ['exception' => $e]);

            return [];
        }
    }

    /**
     * Retrieves the last activity timestamp from the global list.
     */
    public function getLastActivityTime(string $clientId): ?int
    {
        try {
            $activeClientsKey = $this->getActiveClientsCacheKey();
            $activeClients = $this->cache->get($activeClientsKey, []);
            $activeClients = is_array($activeClients) ? $activeClients : [];
            $lastSeen = $activeClients[$clientId] ?? null;

            return is_int($lastSeen) ? $lastSeen : null;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get last activity time.', ['clientId' => $clientId, 'exception' => $e]);

            return null;
        }
    }
}
