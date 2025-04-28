<?php

namespace PhpMcp\Server\JsonRpc;

use JsonSerializable;

class Message implements JsonSerializable
{
    public function __construct(
        public readonly string $jsonrpc,
    ) {
    }

    public function toArray(): array
    {
        return [
            'jsonrpc' => $this->jsonrpc,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
