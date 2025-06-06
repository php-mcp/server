<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contents\ResourceContent;
use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class ReadResourceResult implements ResultInterface
{
    /**
     * Create a new ReadResourceResult.
     *
     * @param  ResourceContent[]  $contents  The contents of the resource
     */
    public function __construct(
        public readonly array $contents
    ) {}


    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        return [
            'contents' => array_map(fn($resource) => $resource->toArray(), $this->contents),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
