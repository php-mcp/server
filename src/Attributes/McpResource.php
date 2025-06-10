<?php

namespace PhpMcp\Server\Attributes;

use Attribute;
use PhpMcp\Schema\Annotations;

/**
 * Marks a PHP class as representing or handling a specific MCP Resource instance.
 * Used primarily for the 'resources/list' discovery.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class McpResource
{
    /**
     * @param  string  $uri  The specific URI identifying this resource instance. Must be unique within the server.
     * @param  ?string  $name  A human-readable name for this resource. If null, a default might be generated from the method name.
     * @param  ?string  $description  An optional description of the resource. Defaults to class DocBlock summary.
     * @param  ?string  $mimeType  The MIME type, if known and constant for this resource.
     * @param  ?int  $size  The size in bytes, if known and constant.
     * @param  Annotations|null  $annotations  Optional annotations describing the resource.
     */
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?Annotations $annotations = null,
    ) {
    }
}
