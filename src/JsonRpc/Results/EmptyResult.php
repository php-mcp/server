<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

/**
 * A generic empty result for methods that return an empty object
 */
class EmptyResult implements ResultInterface
{
    /**
     * Create a new EmptyResult.
     */
    public function __construct() {}

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        return []; // Empty result object
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
