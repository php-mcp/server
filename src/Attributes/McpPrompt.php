<?php

namespace PhpMcp\Server\Attributes;

use Attribute;

/**
 * Marks a PHP method as an MCP Prompt generator.
 * The method should return the prompt messages, potentially using arguments for templating.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class McpPrompt
{
    /**
     * @param  ?string  $name  Overrides the prompt name (defaults to method name).
     * @param  ?string  $description  Optional description of the prompt. Defaults to method DocBlock summary.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}
