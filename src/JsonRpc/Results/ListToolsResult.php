<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\JsonRpc\Result;

class ListToolsResult extends Result
{
    /**
     * @param  array<ToolDefinition>  $tools  The list of tool definitions.
     * @param  string|null  $nextCursor  The cursor for the next page, or null if this is the last page.
     */
    public function __construct(
        public readonly array $tools,
        public readonly ?string $nextCursor = null
    ) {
    }

    public function toArray(): array
    {
        $result =  [
            'tools' => array_map(fn (ToolDefinition $t) => $t->toArray(), $this->tools),
        ];

        if ($this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }
}
