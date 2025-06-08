<?php

namespace PhpMcp\Server\Session;

use Psr\Log\LoggerInterface;

class SubscriptionManager
{
    /** @var array<string, array<string, true>>  Key: URI, Value: array of session IDs */
    private array $resourceSubscribers = [];

    /** @var array<string, array<string, true>>  Key: Session ID, Value: array of URIs */
    private array $sessionSubscriptions = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Subscribe a session to a resource
     */
    public function subscribe(string $sessionId, string $uri): void
    {
        // Add to both mappings for efficient lookup
        $this->resourceSubscribers[$uri][$sessionId] = true;
        $this->sessionSubscriptions[$sessionId][$uri] = true;

        $this->logger->debug('Session subscribed to resource', [
            'sessionId' => $sessionId,
            'uri' => $uri
        ]);
    }

    /**
     * Unsubscribe a session from a resource
     */
    public function unsubscribe(string $sessionId, string $uri): void
    {
        unset($this->resourceSubscribers[$uri][$sessionId]);
        unset($this->sessionSubscriptions[$sessionId][$uri]);

        // Clean up empty arrays
        if (empty($this->resourceSubscribers[$uri])) {
            unset($this->resourceSubscribers[$uri]);
        }

        $this->logger->debug('Session unsubscribed from resource', [
            'sessionId' => $sessionId,
            'uri' => $uri
        ]);
    }

    /**
     * Get all sessions subscribed to a resource
     */
    public function getSubscribers(string $uri): array
    {
        return array_keys($this->resourceSubscribers[$uri] ?? []);
    }

    /**
     * Check if a session is subscribed to a resource
     */
    public function isSubscribed(string $sessionId, string $uri): bool
    {
        return isset($this->sessionSubscriptions[$sessionId][$uri]);
    }

    /**
     * Clean up all subscriptions for a session
     */
    public function cleanupSession(string $sessionId): void
    {
        if (!isset($this->sessionSubscriptions[$sessionId])) {
            return;
        }

        $uris = array_keys($this->sessionSubscriptions[$sessionId]);
        foreach ($uris as $uri) {
            unset($this->resourceSubscribers[$uri][$sessionId]);

            // Clean up empty arrays
            if (empty($this->resourceSubscribers[$uri])) {
                unset($this->resourceSubscribers[$uri]);
            }
        }

        unset($this->sessionSubscriptions[$sessionId]);

        $this->logger->debug('Cleaned up all subscriptions for session', [
            'sessionId' => $sessionId,
            'count' => count($uris)
        ]);
    }
}
