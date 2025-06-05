<?php

namespace PhpMcp\Server\JsonRpc\Contents;

use JsonSerializable;

/**
 * Base class for MCP content types.
 */
abstract class Content implements JsonSerializable
{
    /**
     * Convert the content to an array.
     */
    abstract public function toArray(): array;

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
