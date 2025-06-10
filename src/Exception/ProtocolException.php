<?php

declare(strict_types=1);

namespace PhpMcp\Server\Exception;

use PhpMcp\Schema\JsonRpc\Error as JsonRpcError;

/**
 * Exception related to violations of the JSON-RPC 2.0 or MCP structure
 * in incoming messages or outgoing responses (e.g., missing required fields,
 * invalid types within the protocol itself).
 */
class ProtocolException extends McpServerException
{
    public function toJsonRpcError(string|int $id): JsonRpcError
    {
        $code = ($this->code >= -32700 && $this->code <= -32600) ? $this->code : self::CODE_INVALID_REQUEST;

        return new JsonRpcError(
            jsonrpc: '2.0',
            id: $id,
            code: $code,
            message: $this->getMessage(),
            data: $this->getData()
        );
    }
}
