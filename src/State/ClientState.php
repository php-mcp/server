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
    public array $subscriptions = [];

    /** @var array<string> Queued outgoing framed messages for this client. */
    public array $messageQueue = [];

    public int $lastActivityTimestamp;

    public ?string $requestedLogLevel = null;

    public function __construct(protected string $clientId)
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

    public function addMessageToQueue(string $message): void
    {
        $this->messageQueue[] = $message;
    }

    /** @return array<string> */
    public function consumeMessageQueue(): array
    {
        $messages = $this->messageQueue;
        $this->messageQueue = [];

        return $messages;
    }
}
