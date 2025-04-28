<?php

namespace PhpMcp\Server\JsonRpc;

use PhpMcp\Server\Exceptions\McpException;

class Request extends Message
{
    /**
     * Create a new JSON-RPC 2.0 request.
     *
     * @param  string  $jsonrpc  JSON-RPC version (always "2.0")
     * @param  string|int  $id  Request ID
     * @param  string  $method  Method name
     * @param  array  $params  Method parameters
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly string|int $id,
        public readonly string $method,
        public readonly array $params = [],
    ) {
    }

    /**
     * Create a Request object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC data
     *
     * @throws McpError If the data doesn't conform to JSON-RPC 2.0 structure
     */
    public static function fromArray(array $data): self
    {
        // Validate JSON-RPC 2.0
        if (! isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw McpException::invalidRequest('Invalid or missing "jsonrpc" version. Must be "2.0".');
        }

        // Validate method
        if (! isset($data['method']) || ! is_string($data['method'])) {
            throw McpException::invalidRequest('Invalid or missing "method" field.');
        }

        // Validate ID
        if (! isset($data['id'])) {
            throw McpException::invalidRequest('Invalid or missing "id" field.');
        }

        // Check params if present (optional)
        $params = [];
        if (isset($data['params'])) {
            if (! is_array($data['params'])) {
                throw McpException::invalidRequest('The "params" field must be an array or object.');
            }
            $params = $data['params'];
        }

        return new self(
            $data['jsonrpc'],
            $data['id'],
            $data['method'],
            $params,
        );
    }

    /**
     * Convert the request to an array.
     */
    public function toArray(): array
    {
        $result = [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
            'method' => $this->method,
        ];

        if (! empty($this->params)) {
            $result['params'] = $this->params;
        }

        return $result;
    }
}
