<?php

namespace PhpMcp\Server\JsonRpc;

use JsonSerializable;
use PhpMcp\Server\Exception\ProtocolException;

/**
 * Represents a JSON-RPC response message.
 *
 * @template T
 */
class Response extends Message implements JsonSerializable
{
    /**
     * Create a new JSON-RPC 2.0 response.
     *
     * @param  string  $jsonrpc  JSON-RPC version (always "2.0")
     * @param  string|int|null  $id  Request ID this response is for (must match the request)
     * @param  T|null  $result  Method result (for success) - can be a Result object or array
     * @param  Error|null  $error  Error object (for failure)
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly string|int|null $id,
        public readonly mixed $result = null,
        public readonly ?Error $error = null,
    ) {
        // Responses must have either result or error, not both, UNLESS ID is null (error response)
        if ($this->id !== null && $this->result !== null && $this->error !== null) {
            throw new \InvalidArgumentException('A JSON-RPC response with an ID cannot have both result and error.');
        }

        // A response with an ID MUST have either result or error
        if ($this->id !== null && $this->result === null && $this->error === null) {
            throw new \InvalidArgumentException('A JSON-RPC response with an ID must have either result or error.');
        }

        // A response with null ID MUST have an error and MUST NOT have result
        if ($this->id === null && $this->error === null) {
            throw new \InvalidArgumentException('A JSON-RPC response with null ID must have an error object.');
        }

        if ($this->id === null && $this->result !== null) {
            throw new \InvalidArgumentException('A JSON-RPC response with null ID cannot have a result field.');
        }
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

        // ID must exist for valid responses, but can be null for specific error cases
        // We rely on the constructor validation logic for the result/error/id combinations
        $id = $data['id'] ?? null; // Default to null if missing
        if (! (is_string($id) || is_int($id) || $id === null)) {
            throw new ProtocolException('Invalid "id" field type in response.');
        }

        $hasResult = array_key_exists('result', $data);
        $hasError = array_key_exists('error', $data);

        if ($id !== null) { // If ID is present, standard validation applies
            if ($hasResult && $hasError) {
                throw new ProtocolException('Invalid response: contains both "result" and "error".');
            }
            if (! $hasResult && ! $hasError) {
                throw new ProtocolException('Invalid response: must contain either "result" or "error" when ID is present.');
            }
        } else { // If ID is null, error MUST be present, result MUST NOT
            if (! $hasError) {
                throw new ProtocolException('Invalid response: must contain "error" when ID is null.');
            }
            if ($hasResult) {
                throw new ProtocolException('Invalid response: must not contain "result" when ID is null.');
            }
        }

        $error = null;
        $result = null; // Keep result structure flexible (any JSON type)

        if ($hasError) {
            if (! is_array($data['error'])) { // Error MUST be an object
                throw new ProtocolException('Invalid "error" field in response: must be an object.');
            }
            try {
                $error = Error::fromArray($data['error']);
            } catch (ProtocolException $e) {
                // Wrap error from Error::fromArray for context
                throw new ProtocolException('Invalid "error" object structure in response: '.$e->getMessage(), 0, $e);
            }
        } elseif ($hasResult) {
            $result = $data['result']; // Result can be anything
        }

        try {
            // The constructor now handles the final validation of id/result/error combinations
            return new self('2.0', $id, $result, $error);
        } catch (\InvalidArgumentException $e) {
            // Convert constructor validation error to ProtocolException
            throw new ProtocolException('Invalid response structure: '.$e->getMessage());
        }
    }

    /**
     * Create a successful response.
     *
     * @param  Result  $result  Method result - can be a Result object or array
     * @param  mixed  $id  Request ID
     */
    public static function success(Result $result, mixed $id): self
    {
        return new self(jsonrpc: '2.0', result: $result, id: $id);
    }

    /**
     * Create an error response.
     *
     * @param  Error  $error  Error object
     * @param  string|int|null  $id  Request ID (can be null for parse errors)
     */
    public static function error(Error $error, string|int|null $id): self
    {
        return new self(jsonrpc: '2.0', error: $error, id: $id);
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
            $result['result'] = is_array($this->result) ? $this->result : $this->result->toArray();
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
