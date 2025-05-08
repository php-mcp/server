<?php

declare(strict_types=1);

namespace PhpMcp\Server\Exception;

/**
 * Exception related to violations of the JSON-RPC 2.0 or MCP structure
 * in incoming messages or outgoing responses (e.g., missing required fields,
 * invalid types within the protocol itself).
 */
class ProtocolException extends McpServerException
{
    // This exception often corresponds directly to JSON-RPC error codes.
    // The factory methods in McpServerException can assign appropriate codes.

    public function toJsonRpcError(): \PhpMcp\Server\JsonRpc\Error
    {
        $code = ($this->code >= -32700 && $this->code <= -32600) ? $this->code : self::CODE_INVALID_REQUEST;

        return new \PhpMcp\Server\JsonRpc\Error(
            $code,
            $this->getMessage(),
            $this->getData()
        );
    }
}
