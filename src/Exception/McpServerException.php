<?php

declare(strict_types=1);

namespace PhpMcp\Server\Exception;

use Exception;
use PhpMcp\Schema\Constants;
use PhpMcp\Schema\JsonRpc\Error as JsonRpcError;
use Throwable;

/**
 * Base exception for all MCP Server library errors.
 */
class McpServerException extends Exception
{
    // MCP reserved range: -32000 to -32099 (Server error)
    // Add specific server-side codes if needed later, e.g.:
    // public const CODE_RESOURCE_ACTION_FAILED = -32000;
    // public const CODE_TOOL_EXECUTION_FAILED = -32001;

    /**
     * Additional data associated with the error, suitable for JSON-RPC 'data' field.
     *
     * @var mixed|null
     */
    protected mixed $data = null;

    /**
     * @param  string  $message  Error message.
     * @param  int  $code  Error code (use constants or appropriate HTTP status codes if applicable).
     * @param  mixed|null  $data  Additional data.
     * @param  ?Throwable  $previous  Previous exception.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        mixed $data = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /**
     * Get additional error data.
     *
     * @return mixed|null
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Formats the exception into a JSON-RPC 2.0 error object structure.
     * Specific exceptions should override this or provide factories with correct codes.
     */
    public function toJsonRpcError(string|int $id): JsonRpcError
    {
        $code = ($this->code >= -32768 && $this->code <= -32000) ? $this->code : Constants::INTERNAL_ERROR;

        return new JsonRpcError(
            jsonrpc: '2.0',
            id: $id,
            code: $code,
            message: $this->getMessage(),
            data: $this->getData()
        );
    }

    public static function parseError(string $details, ?Throwable $previous = null): self
    {
        return new ProtocolException('Parse error: ' . $details, Constants::PARSE_ERROR, null, $previous);
    }

    public static function invalidRequest(?string $details = 'Invalid Request', ?Throwable $previous = null): self
    {
        return new ProtocolException($details, Constants::INVALID_REQUEST, null, $previous);
    }

    public static function methodNotFound(string $methodName, ?string $message = null, ?Throwable $previous = null): self
    {
        return new ProtocolException($message ?? "Method not found: {$methodName}", Constants::METHOD_NOT_FOUND, null, $previous);
    }

    public static function invalidParams(string $message = 'Invalid params', $data = null, ?Throwable $previous = null): self
    {
        // Pass data (e.g., validation errors) through
        return new ProtocolException($message, Constants::INVALID_PARAMS, $data, $previous);
    }

    public static function internalError(?string $details = 'Internal server error', ?Throwable $previous = null): self
    {
        $message = 'Internal error';
        if ($details && is_string($details)) {
            $message .= ': ' . $details;
        } elseif ($previous && $details === null) {
            $message .= ' (See server logs)';
        }

        return new McpServerException($message, Constants::INTERNAL_ERROR, null, $previous);
    }

    public static function toolExecutionFailed(string $toolName, ?Throwable $previous = null): self
    {
        $message = "Execution failed for tool '{$toolName}'";
        if ($previous) {
            $message .= ': ' . $previous->getMessage();
        }

        return new McpServerException($message, Constants::INTERNAL_ERROR, null, $previous);
    }

    public static function resourceReadFailed(string $uri, ?Throwable $previous = null): self
    {
        $message = "Failed to read resource '{$uri}'";
        if ($previous) {
            $message .= ': ' . $previous->getMessage();
        }

        return new McpServerException($message, Constants::INTERNAL_ERROR, null, $previous);
    }

    public static function promptGenerationFailed(string $promptName, ?Throwable $previous = null): self
    {
        $message = "Failed to generate prompt '{$promptName}'";
        if ($previous) {
            $message .= ': ' . $previous->getMessage();
        }

        return new McpServerException($message, Constants::INTERNAL_ERROR, null, $previous);
    }
}
