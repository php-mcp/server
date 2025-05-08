<?php

namespace PhpMcp\Server\JsonRpc;

use PhpMcp\Server\Exception\ProtocolException;

class Batch
{
    /**
     * The individual requests/notifications in this batch.
     *
     * @var array<Request|Notification>
     */
    private array $requests = [];

    /**
     * Create a new JSON-RPC 2.0 batch of requests/notifications.
     *
     * @param  array<Request|Notification>  $requests  Optional array of requests to initialize with
     */
    public function __construct(array $requests = [])
    {
        foreach ($requests as $request) {
            $this->addRequest($request);
        }
    }

    /**
     * Create a Batch object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC batch data
     *
     * @throws McpError If the data doesn't conform to JSON-RPC 2.0 batch structure
     */
    public static function fromArray(array $data): self
    {
        if (empty($data)) {
            throw ProtocolException::invalidRequest('A batch must contain at least one request.');
        }

        $batch = new self();

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw ProtocolException::invalidRequest('Each item in a batch must be a valid JSON-RPC object.');
            }

            // Determine if the item is a notification (no id) or a request
            if (! isset($item['id'])) {
                $batch->addRequest(Notification::fromArray($item));
            } else {
                $batch->addRequest(Request::fromArray($item));
            }
        }

        return $batch;
    }

    /**
     * Add a request or notification to the batch.
     *
     * @param  Request|Notification  $request  The request to add
     */
    public function addRequest(Request|Notification $request): self
    {
        $this->requests[] = $request;

        return $this;
    }

    /**
     * Get all requests in this batch.
     *
     * @return array<Request|Notification>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * Get only the requests with IDs (excludes notifications).
     *
     * @return array<Request>
     */
    public function getRequestsWithIds(): array
    {
        return array_filter($this->requests, fn ($r) => ! $r instanceof Notification);
    }

    /**
     * Get only the notifications (requests without IDs).
     *
     * @return array<Notification>
     */
    public function getNotifications(): array
    {
        return array_filter($this->requests, fn ($r) => $r instanceof Notification);
    }

    /**
     * Count the total number of requests in this batch.
     */
    public function count(): int
    {
        return count($this->requests);
    }

    /**
     * Convert the batch to an array.
     */
    public function toArray(): array
    {
        return array_map(fn ($r) => $r->toArray(), $this->requests);
    }
}
