<?php

declare(strict_types=1);

namespace PhpMcp\Server\State;

/**
 * Represents the state data for a single connected client.
 *
 * This object is typically serialized and stored in the cache.
 */
class ClientState
{
    public bool $isInitialized = false;

    public ?array $clientInfo = null;

    public ?string $protocolVersion = null;

    /** @var array<string, true> URIs this client is subscribed to. Key is URI, value is true. */
    public array $subscriptions = []; // This is the client's *view* of its subscriptions

    /** @var array<array> Queued outgoing messages for this client. */
    public array $messageQueue = [];

    public int $lastActivityTimestamp;

    public ?string $requestedLogLevel = null;

    public function __construct(string $clientId) // clientId not stored here, used as cache key
    {
        $this->lastActivityTimestamp = time();
    }

    public function addSubscription(string $uri): void
    {
        $this->subscriptions[$uri] = true;
    }

    public function removeSubscription(string $uri): void
    {
        unset($this->subscriptions[$uri]);
    }

    public function clearSubscriptions(): void
    {
        $this->subscriptions = [];
    }

    public function addMessageToQueue(array $messageData): void
    {
        $this->messageQueue[] = $messageData;
    }

    /** @return array<array> */
    public function consumeMessageQueue(): array
    {
        $messages = $this->messageQueue;
        $this->messageQueue = [];

        return $messages;
    }
}
