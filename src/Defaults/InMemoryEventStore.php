<?php

declare(strict_types=1);

namespace PhpMcp\Server\Defaults;

use PhpMcp\Server\Contracts\EventStoreInterface;

/**
 * Simple in-memory implementation of the EventStore interface for resumability
 * This is primarily intended for examples and testing, not for production use
 * where a persistent storage solution would be more appropriate.
 */
class InMemoryEventStore implements EventStoreInterface
{
    public const DEFAULT_MAX_EVENTS_PER_STREAM = 1000;

    /**
     * @var array<string, array{streamId: string, message: string}>
     * Example: [eventId1 => ['streamId' => 'abc', 'message' => '...']]
     */
    private array $events = [];

    private function generateEventId(string $streamId): string
    {
        return $streamId . '_' . (int)(microtime(true) * 1000) . '_' . bin2hex(random_bytes(4));
    }

    private function getStreamIdFromEventId(string $eventId): ?string
    {
        $parts = explode('_', $eventId);
        return $parts[0] ?? null;
    }

    public function storeEvent(string $streamId, string $message): string
    {
        $eventId = $this->generateEventId($streamId);

        $this->events[$eventId] = [
            'streamId' => $streamId,
            'message' => $message,
        ];

        return $eventId;
    }

    public function replayEventsAfter(string $lastEventId, callable $sendCallback): void
    {
        if (!isset($this->events[$lastEventId])) {
            return;
        }

        $streamId = $this->getStreamIdFromEventId($lastEventId);
        if ($streamId === null) {
            return;
        }

        $foundLastEvent = false;

        // Sort by eventId for deterministic ordering
        ksort($this->events);

        foreach ($this->events as $eventId => ['streamId' => $eventStreamId, 'message' => $message]) {
            if ($eventStreamId !== $streamId) {
                continue;
            }

            if ($eventId === $lastEventId) {
                $foundLastEvent = true;
                continue;
            }

            if ($foundLastEvent) {
                $sendCallback($eventId, $message);
            }
        }
    }
}
