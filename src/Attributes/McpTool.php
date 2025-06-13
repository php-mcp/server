<?php

namespace PhpMcp\Server\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class McpTool
{
    /**
     * @param  string|null  $name  The name of the tool (defaults to the method name)
     * @param  string|null  $description  The description of the tool (defaults to the DocBlock/inferred)
     * @param  array<string, mixed>  $annotations  Optional annotations following the MCP spec (e.g., ['title' => 'my title', 'readOnlyHint' => true]).
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public array $annotations = [],
    ) {
    }
}
