<?php

namespace PhpMcp\Server\Attributes;

use Attribute;

/**
 * Marks a PHP class definition as representing an MCP Resource Template.
 * This is informational, used for 'resources/templates/list'.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpResourceTemplate
{
    /**
     * @param  string  $uriTemplate  The URI template string (RFC 6570).
     * @param  ?string  $name  A human-readable name for the template type.  If null, a default might be generated from the method name.
     * @param  ?string  $description  Optional description. Defaults to class DocBlock summary.
     * @param  ?string  $mimeType  Optional default MIME type for matching resources.
     * @param  ?array<string, mixed>  $annotations  Optional annotations following the MCP spec.
     */
    public function __construct(
        public string $uriTemplate,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?array $annotations = null,
    ) {
    }
}
