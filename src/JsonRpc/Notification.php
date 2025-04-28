<?php

namespace PhpMcp\Server\JsonRpc;

use PhpMcp\Server\Exceptions\McpException;

class Notification extends Message
{
    /**
     * Create a new JSON-RPC 2.0 notification (request without ID).
     *
     * @param  string  $jsonrpc  JSON-RPC version (always "2.0")
     * @param  string  $method  Method name
     * @param  array  $params  Method parameters
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly string $method,
        public readonly array $params = [],
    ) {
    }

    public static function make(string $method, array $params = []): self
    {
        return new self(
            jsonrpc: '2.0',
            method: $method,
            params: $params,
        );
    }

    /**
     * Create a Notification object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC data
     *
     * @throws McpError If the data doesn't conform to JSON-RPC 2.0 notification structure
     */
    public static function fromArray(array $data): self
    {
        // Validate JSON-RPC 2.0
        if (! isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw McpException::invalidRequest('Invalid or missing "jsonrpc" version. Must be "2.0".');
        }

        // Validate method name
        if (! isset($data['method']) || ! is_string($data['method'])) {
            throw McpException::invalidRequest('Invalid or missing "method" field.');
        }

        return new self(
            jsonrpc: $data['jsonrpc'],
            method: $data['method'],
            params: $data['params'] ?? [],
        );
    }


    public function toArray(): array
    {
        $result = [
            'jsonrpc' => $this->jsonrpc,
            'method' => $this->method,
        ];

        if (! empty($this->params)) {
            $result['params'] = $this->params;
        }

        return $result;
    }
}
