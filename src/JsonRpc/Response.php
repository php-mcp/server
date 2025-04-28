<?php

namespace PhpMcp\Server\JsonRpc;

use PhpMcp\Server\Exceptions\McpException;
use JsonSerializable;

/**
 * Represents a JSON-RPC response message.
 */
class Response extends Message implements JsonSerializable
{
    /**
     * Create a new JSON-RPC 2.0 response.
     *
     * @param  string  $jsonrpc  JSON-RPC version (always "2.0")
     * @param  string|int  $id  Request ID this response is for (must match the request)
     * @param  Result  $result  Method result (for success) - can be a Result object or array
     * @param  Error|null  $error  Error object (for failure)
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly string|int $id,
        public readonly ?Result $result = null,
        public readonly ?Error $error = null,
    ) {
        // Responses must have either result or error, not both
        if ($this->result !== null && $this->error !== null) {
            throw new \InvalidArgumentException(
                'A JSON-RPC response cannot have both result and error.'
            );
        }
    }

    /**
     * Create a Response object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC response data
     *
     * @throws McpError If the data doesn't conform to JSON-RPC 2.0 structure
     */
    public static function fromArray(array $data): self
    {
        // Validate JSON-RPC 2.0
        if (! isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw McpException::invalidRequest('Invalid or missing "jsonrpc" version. Must be "2.0".');
        }

        // Validate contains either result or error
        if (! isset($data['result']) && ! isset($data['error'])) {
            throw McpException::invalidRequest('Response must contain either "result" or "error".');
        }

        // Validate ID
        if (! isset($data['id'])) {
            throw McpException::invalidRequest('Invalid or missing "id" field.');
        }

        // Handle error if present
        $error = null;
        if (isset($data['error'])) {
            if (! is_array($data['error'])) {
                throw McpException::invalidRequest('The "error" field must be an object.');
            }

            if (! isset($data['error']['code']) || ! isset($data['error']['message'])) {
                throw McpException::invalidRequest('Error object must contain "code" and "message" fields.');
            }

            $error = Error::fromArray($data['error']);
        }


        return new self(
            $data['jsonrpc'],
            $data['id'],
            $data['result'] ?? null,
            $error,
        );
    }

    /**
     * Create a successful response.
     *
     * @param  Result  $result  Method result - can be a Result object or array
     * @param  mixed  $id  Request ID
     */
    public static function success(Result $result, mixed $id): self
    {
        return new self(
            jsonrpc: '2.0',
            result: $result,
            id: $id,
        );
    }

    /**
     * Create an error response.
     *
     * @param  Error  $error  Error object
     * @param  mixed  $id  Request ID (can be null for parse errors)
     */
    public static function error(Error $error, mixed $id): self
    {
        return new self(
            jsonrpc: '2.0',
            error: $error,
            id: $id,
        );
    }

    /**
     * Check if this response is a success response.
     */
    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * Check if this response is an error response.
     */
    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Convert the response to an array.
     */
    public function toArray(): array
    {
        $result = [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
        ];

        if ($this->isSuccess()) {
            $result['result'] = $this->result->toArray();
        } else {
            $result['error'] = $this->error->toArray();
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
