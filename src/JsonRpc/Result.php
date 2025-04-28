<?php

namespace PhpMcp\Server\JsonRpc;

use JsonSerializable;

/**
 * Base class for all JSON-RPC result objects
 */
abstract class Result implements JsonSerializable
{
    /**
     * Convert the result object to its JSON representation.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Convert the result object to an array.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
