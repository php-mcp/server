<?php

namespace PhpMcp\Server\JsonRpc\Messages;

use PhpMcp\Server\JsonRpc\Contracts\MessageInterface;

/**
 * A JSON-RPC batch response, as described in https://www.jsonrpc.org/specification#batch.
 */
class BatchResponse extends Message
{
    /**
     * The individual requests/notifications in this batch.
     *
     * @var array<Response|Error>
     */
    private array $responses = [];

    /**
     * Create a new JSON-RPC 2.0 batch of requests/notifications.
     *
     * @param  array<Response|Error>  $responses  Optional array of responses to initialize with
     */
    public function __construct(array $responses = [])
    {
        foreach ($responses as $response) {
            $this->add($response);
        }
    }

    public function getId(): string|int|null
    {
        return null;
    }

    public static function fromArray(array $data): self
    {
        $batch = new self();

        foreach ($data as $response) {
            $batch->add(Message::parseResponse($response));
        }

        return $batch;
    }

    /**
     * Add a response to the batch.
     *
     * @param  Response|Error  $response  The response to add
     */
    public function add(Response|Error $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * Get all requests in this batch.
     *
     * @return array<Response|Error>
     */
    public function all(): array
    {
        return $this->responses;
    }

    /**
     * Get only the requests with IDs (excludes notifications).
     *
     * @return array<Response>
     */
    public function getResponses(): array
    {
        return array_filter($this->responses, fn ($r) => $r instanceof Response);
    }

    /**
     * Get only the notifications (requests without IDs).
     *
     * @return array<Notification>
     */
    public function getErrors(): array
    {
        return array_filter($this->responses, fn ($r) => $r instanceof Error);
    }

    public function isEmpty(): bool
    {
        return empty($this->responses);
    }

    /**
     * Count the total number of requests in this batch.
     */
    public function count(): int
    {
        return count($this->responses);
    }

    /**
     * Convert the batch to an array.
     */
    public function toArray(): array
    {
        return array_map(fn ($r) => $r->toArray(), $this->responses);
    }
}
