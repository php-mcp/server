<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Defaults\ArrayCache;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

class SessionManager
{
    protected string $cachePrefix = 'mcp_session_';
    public const GLOBAL_ACTIVE_SESSIONS_KEY = 'mcp_active_sessions';
    public const GLOBAL_RESOURCE_SUBSCRIBERS_KEY_PREFIX = 'mcp_res_subs_';
    protected array $activeSessions = [];

    public function __construct(
        protected LoggerInterface $logger,
        protected ?CacheInterface $cache = null,
        protected int $cacheTtl = 3600
    ) {
        $this->cache ??= new ArrayCache();
    }

    protected function getActiveSessionsCacheKey(): string
    {
        return $this->cachePrefix . self::GLOBAL_ACTIVE_SESSIONS_KEY;
    }

    protected function getResourceSubscribersCacheKey(string $uri): string
    {
        return self::GLOBAL_RESOURCE_SUBSCRIBERS_KEY_PREFIX . sha1($uri);
    }

    public function getSession(string $sessionId, bool $createIfNotFound = false): ?SessionInterface
    {
        $key = $this->cachePrefix . $sessionId;
        $json = $this->cache->get($key);

        if ($json === null) {
            return $createIfNotFound ? new Session($sessionId) : null;
        }

        try {
            $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $session = new Session($sessionId);
            $session->setAttributes($attributes);
            return $session;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to decode session data from cache.', ['sessionId' => $sessionId, 'key' => $key, 'exception' => $e]);
            $this->cache->delete($key);
            return $createIfNotFound ? new Session($sessionId) : null;
        }
    }

    public function isSessionInitialized(string $sessionId): bool
    {
        $session = $this->getSession($sessionId);
        return $session !== null && $session->isInitialized();
    }

    public function initializeSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId, true);
        $session->initialize();

        if ($this->saveSession($session)) {
            $this->activeSessions[] = $sessionId;
        }
    }

    public function saveSession(SessionInterface $session): bool
    {
        try {
            $key = $this->cachePrefix . $session->getId();
            $json = json_encode($session, JSON_THROW_ON_ERROR);
            return $this->cache->set($key, $json, $this->cacheTtl);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to save session data to cache.', ['sessionId' => $session->getId(), 'exception' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteSession(string $sessionId, bool $updateCache = true): bool
    {
        $this->removeAllResourceSubscriptions($sessionId);

        $key = $this->cachePrefix . $sessionId;

        try {
            $this->cache->delete($key);
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete session.', ['sessionId' => $sessionId, 'exception' => $e]);
        }

        if ($updateCache) {
            $activeSessionsKey = $this->getActiveSessionsCacheKey();
            try {
                $activeSessions = $this->cache->get($activeSessionsKey, []);

                if (isset($activeSessions[$sessionId])) {
                    unset($activeSessions[$sessionId]);
                    $this->cache->set($activeSessionsKey, $activeSessions, $this->cacheTtl);
                }
            } catch (Throwable $e) {
                $this->logger->error('Failed to update global active sessions list during cleanup.', ['sessionId' => $sessionId, 'exception' => $e]);
            }
        }

        $this->logger->info('Session deleted.', ['sessionId' => $sessionId]);

        return true;
    }

    public function touchSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return;
        $session->touch();
        $this->saveSession($session);
    }

    public function getActiveSessions(int $inactiveThreshold = 300): array
    {
        try {
            $activeSessionsKey = $this->getActiveSessionsCacheKey();
            $activeSessions = $this->cache->get($activeSessionsKey, []);

            $currentTimeStamp = time();
            $sessionsToCleanUp = [];

            foreach ($activeSessions as $sessionId) {
                $session = $this->getSession($sessionId, false);
                if (!$session) {
                    $sessionsToCleanUp[] = $sessionId;
                    continue;
                }

                $lastActivityTimestamp = $session->getAttribute('last_activity_timestamp');
                if ($currentTimeStamp - $lastActivityTimestamp > $inactiveThreshold) {
                    $sessionsToCleanUp[] = $sessionId;
                }
            }

            foreach ($sessionsToCleanUp as $sessionIdToClean) {
                unset($activeSessions[$sessionIdToClean]);
                $this->deleteSession($sessionIdToClean, false);
            }

            $this->cache->set($activeSessionsKey, $activeSessions, $this->cacheTtl);

            return $activeSessions;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get active sessions.', ['exception' => $e]);

            return [];
        }
    }

    public function storeClientInfo(string $sessionId, array $clientInfo): void
    {
        $session = $this->getSession($sessionId, true);
        $session->setAttribute('client_info', $clientInfo);
        $this->saveSession($session);
    }

    public function addResourceSubscription(string $sessionId, string $uri): void
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return;

        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);

        try {
            $subscriptions = $session->getAttribute('subscriptions', []);
            $subscriptions[$uri] = true;
            $session->setAttribute('subscriptions', $subscriptions);
            $this->saveSession($session);

            $subscribers = $this->cache->get($resourceSubKey, []);
            $subscribers = is_array($subscribers) ? $subscribers : [];
            $subscribers[$sessionId] = true;
            $this->cache->set($resourceSubKey, $subscribers, $this->cacheTtl);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to add resource subscription to session.', ['sessionId' => $sessionId, 'uri' => $uri, 'exception' => $e->getMessage()]);
        }
    }

    public function removeResourceSubscription(string $sessionId, string $uri): void
    {
        $session = $this->getSession($sessionId, true);
        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);

        try {
            if ($session) {
                $subscriptions = $session->getAttribute('subscriptions', []);
                unset($subscriptions[$uri]);
                $session->setAttribute('subscriptions', $subscriptions);
                $this->saveSession($session);
            }

            $subscribers = $this->cache->get($resourceSubKey, []);
            $subscribers = is_array($subscribers) ? $subscribers : [];
            $changed = false;

            if (isset($subscribers[$sessionId])) {
                unset($subscribers[$sessionId]);
                $changed = true;
            }

            if ($changed) {
                if (empty($subscribers)) {
                    $this->cache->delete($resourceSubKey);
                } else {
                    $this->cache->set($resourceSubKey, $subscribers, $this->cacheTtl);
                }
                $this->logger->debug('Session unsubscribed from resource.', ['sessionId' => $sessionId, 'uri' => $uri]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to remove resource subscription from session.', ['sessionId' => $sessionId, 'uri' => $uri, 'exception' => $e->getMessage()]);
        }
    }

    public function removeAllResourceSubscriptions(string $sessionId): void
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null || empty($session->getAttribute('subscriptions'))) return;

        $urisSessionWasSubscribedTo = array_keys($session->getAttribute('subscriptions'));

        try {
            $session->forgetAttribute('subscriptions');
            $this->saveSession($session);

            foreach ($urisSessionWasSubscribedTo as $uri) {
                $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);
                $subscribers = $this->cache->get($resourceSubKey, []);
                $subscribers = is_array($subscribers) ? $subscribers : [];
                if (isset($subscribers[$sessionId])) {
                    unset($subscribers[$sessionId]);
                    if (empty($subscribers)) {
                        $this->cache->delete($resourceSubKey);
                    } else {
                        $this->cache->set($resourceSubKey, $subscribers, $this->cacheTtl);
                    }
                }
            }
            $this->logger->debug('Removed all resource subscriptions for session.', ['sessionId' => $sessionId, 'count' => count($urisSessionWasSubscribedTo)]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to remove all resource subscriptions.', ['sessionId' => $sessionId, 'exception' => $e]);
        }
    }

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

    public function isSubscribedToResource(string $sessionId, string $uri): bool
    {
        $resourceSubKey = $this->getResourceSubscribersCacheKey($uri);

        try {
            $subscribers = $this->cache->get($resourceSubKey, []);

            return is_array($subscribers) && isset($subscribers[$sessionId]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to check resource subscription.', ['sessionId' => $sessionId, 'uri' => $uri, 'exception' => $e]);

            return false;
        }
    }

    public function queueMessage(string $sessionId, string $message): void
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return;

        $session->queueMessage($message);
        $this->saveSession($session);
    }

    public function dequeueMessages(string $sessionId): array
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return [];

        $messages = $session->dequeueMessages();
        $this->saveSession($session);
        return $messages;
    }

    public function hasQueuedMessages(string $sessionId): bool
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return false;

        return $session->hasQueuedMessages();
    }

    public function queueMessageForAll(string $message): void
    {
        $activeSessions = $this->getActiveSessions();

        foreach ($activeSessions as $sessionId) {
            $this->queueMessage($sessionId, $message);
        }
    }

    public function setLogLevel(string $sessionId, string $level): void
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return;

        $session->setAttribute('log_level', $level);
        $this->saveSession($session);
    }

    public function getLogLevel(string $sessionId): ?string
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return null;

        return $session->getAttribute('log_level');
    }
}
