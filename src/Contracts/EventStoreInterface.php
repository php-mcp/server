<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

/**
 * Interface for resumability support via event storage
 */
interface EventStoreInterface
{
    /**
     * Stores a message associated with a specific stream and returns a unique event ID.
     *
     * @param string $streamId The ID of the stream the event belongs to.
     * @param string $message The framed JSON-RPC message to store.
     * @return string The generated event ID for the stored event
     */
    public function storeEvent(string $streamId, string $message): string;

    /**
     * Replays events for a given stream that occurred after a specific event ID.
     *
     * @param string $lastEventId The last event ID the client received for this specific stream.
     * @param callable $sendCallback A function to call for each replayed message.
     *                           The callback will receive: `function(string $eventId, Message $message): void`
     */
    public function replayEventsAfter(string $lastEventId, callable $sendCallback): void;
}
