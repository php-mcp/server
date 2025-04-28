<?php

namespace PhpMcp\Server\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class McpTool
{
    /**
     * @param  string|null  $name  The name of the tool (defaults to the method name)
     * @param  string|null  $description  The description of the tool (defaults to the DocBlock/inferred)
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}
