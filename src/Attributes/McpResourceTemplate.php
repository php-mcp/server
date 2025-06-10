<?php

namespace PhpMcp\Server\Attributes;

use Attribute;
use PhpMcp\Schema\Annotations;

/**
 * Marks a PHP class definition as representing an MCP Resource Template.
 * This is informational, used for 'resources/templates/list'.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class McpResourceTemplate
{
    /**
     * @param  string  $uriTemplate  The URI template string (RFC 6570).
     * @param  ?string  $name  A human-readable name for the template type.  If null, a default might be generated from the method name.
     * @param  ?string  $description  Optional description. Defaults to class DocBlock summary.
     * @param  ?string  $mimeType  Optional default MIME type for matching resources.
     * @param  ?Annotations  $annotations  Optional annotations describing the resource template.
     */
    public function __construct(
        public string $uriTemplate,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?Annotations $annotations = null,
    ) {
    }
}
