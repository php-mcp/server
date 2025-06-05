<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contents\ResourceContent;
use PhpMcp\Server\JsonRpc\Result;

class ReadResourceResult extends Result
{
    /**
     * Create a new ReadResourceResult.
     *
     * @param  ResourceContent[]  $contents  The contents of the resource
     */
    public function __construct(
        protected array $contents
    ) {}

    /**
     * Get the contents of the resource.
     *
     * @return ResourceContent[]
     */
    public function getContents(): array
    {
        return $this->contents;
    }

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        return [
            'contents' => array_map(fn($resource) => $resource->toArray(), $this->contents),
        ];
    }
}
