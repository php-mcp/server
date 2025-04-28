<?php

namespace PhpMcp\Server\JsonRpc\Results;

use Codewithkyrian\LaravelMcp\JsonRpc\Types\EmbeddedResource;
use PhpMcp\Server\JsonRpc\Result;

class ReadResourceResult extends Result
{
    /**
     * Create a new ReadResourceResult.
     *
     * @param  EmbeddedResource[]  $contents  The contents of the resource
     */
    public function __construct(
        protected array $contents
    ) {
    }

    /**
     * Get the contents of the resource.
     *
     * @return EmbeddedResource[]
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
            'contents' => array_map(fn ($resource) => $resource->toArray(), $this->contents),
        ];
    }
}
