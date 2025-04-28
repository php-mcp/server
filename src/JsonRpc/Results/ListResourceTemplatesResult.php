<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\JsonRpc\Result;

class ListResourceTemplatesResult extends Result
{
    /**
     * @param  array<ResourceTemplateDefinition>  $resourceTemplates  The list of resource template definitions.
     * @param  string|null  $nextCursor  The cursor for the next page, or null if this is the last page.
     */
    public function __construct(
        public readonly array $resourceTemplates,
        public readonly ?string $nextCursor = null
    ) {
    }

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        $result = [
            'resourceTemplates' => array_map(fn (ResourceTemplateDefinition $t) => $t->toArray(), $this->resourceTemplates),
        ];

        if ($this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }
}
