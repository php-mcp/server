<?php

namespace PhpMcp\Server\Attributes;

use Attribute;
use PhpMcp\Schema\ToolAnnotations;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class McpTool
{
    /**
     * @param  string|null  $name  The name of the tool (defaults to the method name)
     * @param  string|null  $description  The description of the tool (defaults to the DocBlock/inferred)
     * @param  ToolAnnotations|null  $annotations  Optional annotations describing tool behavior
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?ToolAnnotations $annotations = null,
    ) {
    }
}
