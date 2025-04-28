<?php

namespace PhpMcp\Server\Exceptions;

use Exception;
use PhpMcp\Server\JsonRpc\Error;
use Throwable;

/**
 * Represents a JSON-RPC 2.0 Error specific to MCP interactions.
 */
class McpException extends Exception
{
    // Standard JSON-RPC 2.0 Error Codes
    public const CODE_PARSE_ERROR = -32700;

    public const CODE_INVALID_REQUEST = -32600;

    public const CODE_METHOD_NOT_FOUND = -32601;

    public const CODE_INVALID_PARAMS = -32602;

    public const CODE_INTERNAL_ERROR = -32603;

    // MCP reserved range: -32000 to -32099 (Server error)
    // Example:
    // public const RESOURCE_ACTION_FAILED = -32000;
    // public const TOOL_EXECUTION_FAILED = -32001; // Distinct from protocol errors

    /**
     * Additional data associated with the error.
     *
     * @var mixed|null
     */
    protected mixed $data;

    /**
     * @param  string  $message  Error message.
     * @param  int  $code  Error code (use constants).
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

    // --- Static Factory Methods for Common Errors ---

    public static function parseError(string $details, ?Throwable $previous = null): self
    {
        return new self('Could not parse message: '.$details, self::CODE_PARSE_ERROR, null, $previous);
    }

    public static function invalidRequest(?string $details = null, ?Throwable $previous = null): self
    {
        return new self('Invalid Request: '.$details, self::CODE_INVALID_REQUEST, null, $previous);
    }

    public static function methodNotFound(string $methodName, ?Throwable $previous = null): self
    {
        return new self("Method not found: {$methodName}", self::CODE_METHOD_NOT_FOUND, null, $previous);
    }

    public static function toolNotFound(string $toolName, ?Throwable $previous = null): self
    {
        return new self("Tool not found: {$toolName}", self::CODE_METHOD_NOT_FOUND, null, $previous);
    }

    public static function invalidParams($message = null, $data = null, ?Throwable $previous = null): self
    {
        return new self($message ?? 'Invalid params', self::CODE_INVALID_PARAMS, $data, $previous);
    }

    public static function internalError(?string $details = null, ?Throwable $previous = null): self
    {
        $message = 'Internal error';
        if ($details && is_string($details)) {
            $message .= ': '.$details;
        } elseif ($previous) {
            $message .= ' (See server logs)';
        }

        return new self($message, self::CODE_INTERNAL_ERROR, null, $previous);
    }

    public static function methodExecutionFailed(string $methodName, ?Throwable $previous = null): self
    {
        return new self("Execution failed for method '{$methodName}': {$previous->getMessage()}", self::CODE_INTERNAL_ERROR, null, $previous);
    }

    /**
     * Formats the exception into a JSON-RPC 2.0 error object structure.
     */
    public function toJsonRpcError(): Error
    {
        return new Error($this->getCode(), $this->getMessage(), $this->getData());
    }
}
