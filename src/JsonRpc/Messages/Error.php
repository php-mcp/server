<?php

namespace PhpMcp\Server\JsonRpc\Messages;

use PhpMcp\Server\Exception\ProtocolException;

/**
 * A response to a request that indicates an error occurred.
 */
class Error extends Message
{
    public const CODE_PARSE_ERROR = -32700;

    public const CODE_INVALID_REQUEST = -32600;

    public const CODE_METHOD_NOT_FOUND = -32601;

    public const CODE_INVALID_PARAMS = -32602;

    public const CODE_INTERNAL_ERROR = -32603;


    // Internal Errors
    public const CODE_CONNECTION_CLOSED = -32000;
    public const CODE_REQUEST_TIMEOUT = -32001;

    /**
     * Create a new JSON-RPC 2.0 error.
     *
     * @param  int  $code  The error type that occurred.
     * @param  string  $message  A short description of the error. The message SHOULD be limited to a concise single sentence.
     * @param  mixed  $data  Additional information about the error. The value of this member is defined by the sender (e.g. detailed error information, nested errors etc.).
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly string|int $id,
        public readonly int $code,
        public readonly string $message,
        public readonly mixed $data = null
    ) {
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public static function parseError(string $message): self
    {
        return new self(
            jsonrpc: '2.0',
            id: '',
            code: self::CODE_PARSE_ERROR,
            message: $message,
            data: null,
        );
    }

    public static function invalidRequest(string $message, string $id = ''): self
    {
        return new self(
            jsonrpc: '2.0',
            id: $id,
            code: self::CODE_INVALID_REQUEST,
            message: $message,
            data: null,
        );
    }

    public static function connectionAborted(string $message): self
    {
        return new self(
            jsonrpc: '2.0',
            id: '',
            code: self::CODE_CONNECTION_CLOSED,
            message: $message,
            data: null,
        );
    }

    /**
     * Create an Error object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC error data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['error']) || ! is_array($data['error'])) {
            throw ProtocolException::invalidRequest('Invalid or missing "error" field.');
        }

        return new self(
            jsonrpc: '2.0',
            id: $data['id'] ?? null,
            code: $data['error']['code'],
            message: $data['error']['message'],
            data: $data['error']['data'] ?? null
        );
    }

    /**
     * Convert the error to an array.
     */
    public function toArray(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
            'error' => $error,
        ];
    }
}
