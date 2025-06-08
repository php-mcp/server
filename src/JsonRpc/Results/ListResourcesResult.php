<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class ListResourcesResult implements ResultInterface
{
    /**
     * @param  array<ResourceDefinition>  $resources  The list of resource definitions.
     * @param  string|null  $nextCursor  The cursor for the next page, or null if this is the last page.
     */
    public function __construct(
        public readonly array $resources,
        public readonly ?string $nextCursor = null
    ) {
    }

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        $result = [
            'resources' => array_map(fn (ResourceDefinition $r) => $r->toArray(), $this->resources),
        ];

        if ($this->nextCursor !== null) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
