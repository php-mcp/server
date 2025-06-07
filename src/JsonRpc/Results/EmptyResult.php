<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

/**
 * A generic empty result that indicates success but carries no data.
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

    public function jsonSerialize(): mixed
    {
        return new \stdClass();
    }
}
