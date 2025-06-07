<?php

namespace PhpMcp\Server\JsonRpc\Messages;

use PhpMcp\Server\Exception\ProtocolException;
use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

/**
 * A successful (non-error) response to a request.
 *
 * @template T of ResultInterface
 */
class Response extends Message
{
    /**
     * Create a new JSON-RPC 2.0 response.
     *
     * @param  string  $jsonrpc  JSON-RPC version (always "2.0")
     * @param  string|int  $id  Request ID this response is for (must match the request)
     * @param  T  $result  Method result
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly string|int $id,
        public readonly mixed $result,
    ) {
        if ($this->result === null) {
            throw new \InvalidArgumentException('A JSON-RPC response with an ID must have a valid result.');
        }
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    /**
     * Create a Response object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC response data
     *
     * @throws ProtocolException If the data doesn't conform to JSON-RPC 2.0 structure
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new ProtocolException('Invalid or missing "jsonrpc" version. Must be "2.0".');
        }

        $id = $data['id'] ?? null;
        if (! (is_string($id) || is_int($id) || $id === null)) {
            throw new ProtocolException('Invalid "id" field type in response.');
        }

        $result = $data['result'];

        try {
            return new self('2.0', $id, $result);
        } catch (\InvalidArgumentException $e) {
            throw new ProtocolException('Invalid response structure: ' . $e->getMessage());
        }
    }

    /**
     * Create a successful response.
     *
     * @param  T  $result  Method result
     * @param  mixed  $id  Request ID
     */
    public static function make(mixed $result, string|int $id): self
    {
        return new self(jsonrpc: '2.0', result: $result, id: $id);
    }

    /**
     * Convert the response to an array.
     */
    public function toArray(): array
    {
        return [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
            'result' => $this->result->toArray(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
            'result' => $this->result->jsonSerialize(),
        ];
    }
}
