<?php

namespace PhpMcp\Server\Definitions;

use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Model\Annotations;
use PhpMcp\Server\Support\DocBlockParser;
use ReflectionMethod;

/**
 * Represents a discovered MCP Resource, compliant with the MCP specification.
 * This definition is primarily informational for 'resources/list'.
 * The actual handling of a resource URI happens via methods like 'resources/read'.
 */
class ResourceDefinition
{
    /**
     * Resource name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * URI pattern regex - requires a valid scheme, followed by colon and optional path.
     * Example patterns: config://, file://path, db://table, etc.
     */
    private const URI_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\s]*$/';

    /**
     * @param  class-string  $className  The fully qualified class name containing the resource handler method.
     * @param  string  $methodName  The name of the PHP method implementing the resource handler.
     * @param  string  $uri  The URI identifying this specific resource instance.
     * @param  string  $name  A human-readable name for this resource.
     * @param  string|null  $description  A description of what this resource represents.
     * @param  string|null  $mimeType  The MIME type of this resource, if known.
     * @param  ?Annotations  $annotations  Optional annotations describing the resource.
     * @param  int|null  $size  The size of the raw resource content, in bytes (i.e., before base64 encoding or any tokenization), if known
     *
     * @throws \InvalidArgumentException If the URI doesn't match the required pattern.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $uri,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $mimeType,
        public readonly ?Annotations $annotations,
        public readonly ?int $size,
    ) {
        $this->validate();
    }

    /**
     * Validates the definition parameters
     *
     * @throws \InvalidArgumentException If the URI is invalid
     */
    private function validate(): void
    {
        if (! preg_match(self::URI_PATTERN, $this->uri)) {
            throw new \InvalidArgumentException(
                "Resource URI '{$this->uri}' is invalid. URIs must match the pattern " . self::URI_PATTERN
                    . ' (valid scheme followed by :// and optional path).'
            );
        }

        if (! preg_match(self::RESOURCE_NAME_PATTERN, $this->name)) {
            throw new \InvalidArgumentException(
                "Resource name '{$this->name}' is invalid. Resource names must match the pattern " . self::RESOURCE_NAME_PATTERN
                    . ' (alphanumeric characters, underscores, and hyphens only).'
            );
        }
    }

    /**
     * Formats the definition into the structure expected by MCP's 'resources/list'.
     *
     * @return array{uri: string, name: string, description?: string, mimeType?: string, size?: int, annotations?: array}
     */
    public function toArray(): array
    {
        $data = [
            'uri' => $this->uri,
            'name' => $this->name,
        ];
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        if ($this->annotations !== null) {
            $data['annotations'] = $this->annotations->toArray();
        }
        if ($this->size !== null) {
            $data['size'] = $this->size;
        }

        return $data;
    }

    /**
     * Reconstruct a ResourceDefinition from its array representation.
     *
     * @param  array  $data  The array representation of a ResourceDefinition
     * @return static The reconstructed ResourceDefinition
     */
    public static function fromArray(array $data): static
    {
        $annotations = isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null;

        return new self(
            className: $data['className'],
            methodName: $data['methodName'],
            uri: $data['uri'],
            name: $data['name'],
            description: $data['description'],
            mimeType: $data['mimeType'],
            annotations: $annotations,
            size: $data['size'],
        );
    }

    /**
     * Create a ResourceDefinition from reflection data.
     *
     * @param  ReflectionMethod  $method  The reflection method marked with McpResource.
     * @param  McpResource  $attribute  The attribute instance.
     * @param  DocBlockParser  $docBlockParser  Utility to parse docblocks.
     */
    public static function fromReflection(
        ReflectionMethod $method,
        ?string $overrideName,
        ?string $overrideDescription,
        string $uri,
        ?string $mimeType,
        ?Annotations $annotations,
        ?int $size,
        DocBlockParser $docBlockParser
    ): self {
        $docBlock = $docBlockParser->parseDocBlock($method->getDocComment() ?: null);
        $description = $overrideDescription ?? $docBlockParser->getSummary($docBlock) ?? null;

        $name = $overrideName ?? ($method->getName() === '__invoke'
            ? $method->getDeclaringClass()->getShortName()
            : $method->getName());

        return new self(
            className: $method->getDeclaringClass()->getName(),
            methodName: $method->getName(),
            uri: $uri,
            name: $name,
            description: $description,
            mimeType: $mimeType,
            annotations: $annotations,
            size: $size,
        );
    }
}
