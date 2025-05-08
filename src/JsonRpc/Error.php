<?php

namespace PhpMcp\Server\JsonRpc;

use PhpMcp\Server\Exception\ProtocolException;

class Error
{
    /**
     * Create a new JSON-RPC 2.0 error.
     *
     * @param  int  $code  Error code
     * @param  string  $message  Error message
     * @param  mixed  $data  Additional error data (optional)
     */
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly mixed $data = null
    ) {
    }

    /**
     * Create an Error object from an array representation.
     *
     * @param  array  $data  Raw decoded JSON-RPC error data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['code']) || ! is_int($data['code'])) {
            throw ProtocolException::invalidRequest('Invalid or missing "code" field.');
        }

        return new self(
            $data['code'],
            $data['message'],
            $data['data'] ?? null
        );
    }

    /**
     * Convert the error to an array.
     */
    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->data !== null) {
            $result['data'] = $this->data;
        }

        return $result;
    }
}
