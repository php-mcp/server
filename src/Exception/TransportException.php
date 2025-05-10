<?php

declare(strict_types=1);

namespace PhpMcp\Server\Exception;

/**
 * Exception related to errors in the underlying transport layer
 * (e.g., socket errors, process management issues, SSE stream errors).
 */
class TransportException extends McpServerException
{
    // Usually indicates an internal server error if it prevents request processing.
    public function toJsonRpcError(): \PhpMcp\Server\JsonRpc\Error
    {
        // Override to ensure it maps to internal error for JSON-RPC responses
        return new \PhpMcp\Server\JsonRpc\Error(
            self::CODE_INTERNAL_ERROR,
            'Transport layer error: '.$this->getMessage(),
            null
        );
    }
}
