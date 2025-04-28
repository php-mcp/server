<?php

namespace PhpMcp\Server\Definitions;

use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Support\DocBlockParser;
use ReflectionMethod;

/**
 * Represents a discovered MCP Resource Template. Compliant with MCP specification.
 * Used for the 'resources/templates/list' method.
 */
class ResourceTemplateDefinition
{
    /**
     * Resource name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * URI Template pattern regex - requires a valid scheme, followed by colon and path with at least one placeholder.
     * Example patterns: config://{key}, file://{path}/contents.txt, db://{table}/{id}, etc.
     */
    private const URI_TEMPLATE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/.*{[^{}]+}.*/';

    /**
     * @param  class-string  $className  The fully qualified class name containing the template handler method.
     * @param  string  $methodName  The name of the PHP method implementing the template handler.
     * @param  string  $uriTemplate  A URI template (RFC 6570).
     * @param  string  $name  A human-readable name for the template type.
     * @param  string|null  $description  A description of what this template is for.
     * @param  string|null  $mimeType  Optional default MIME type for resources matching this template.
     * @param  array<string, mixed>  $annotations  Optional annotations (audience, priority).
     *
     * @throws \InvalidArgumentException If the URI template doesn't match the required pattern.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $uriTemplate,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $mimeType,
        public readonly array $annotations = []
    ) {
        $this->validate();
    }

    /**
     * Validates the definition parameters
     *
     * @throws \InvalidArgumentException If the URI template is invalid
     */
    private function validate(): void
    {
        if (! preg_match(self::URI_TEMPLATE_PATTERN, $this->uriTemplate)) {
            throw new \InvalidArgumentException(
                "Resource URI template '{$this->uriTemplate}' is invalid. URI templates must match the pattern "
                .self::URI_TEMPLATE_PATTERN.' (valid scheme followed by :// and path with placeholder(s) in curly braces).'
            );
        }

        if (! preg_match(self::RESOURCE_NAME_PATTERN, $this->name)) {
            throw new \InvalidArgumentException(
                "Resource name '{$this->name}' is invalid. Resource names must match the pattern ".self::RESOURCE_NAME_PATTERN
                .' (alphanumeric characters, underscores, and hyphens only).'
            );
        }
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getUriTemplate(): string
    {
        return $this->uriTemplate;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getAnnotations(): array
    {
        return $this->annotations;
    }

    /**
     * Formats the definition into the structure expected by MCP's 'resources/templates/list'.
     *
     * @return array{uriTemplate: string, name: string, description?: string, mimeType?: string, annotations?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $data = [
            'uriTemplate' => $this->uriTemplate,
            'name' => $this->name,
        ];
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        if (! empty($this->annotations)) {
            $data['annotations'] = $this->annotations;
        }

        return $data;
    }

    /**
     * Reconstruct a ResourceTemplateDefinition from its array representation.
     *
     * @param  array  $data  The array representation of a ResourceTemplateDefinition
     * @return static The reconstructed ResourceTemplateDefinition
     */
    public static function fromArray(array $data): static
    {
        return new self(
            className: $data['className'],
            methodName: $data['methodName'],
            uriTemplate: $data['uriTemplate'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: $data['annotations'] ?? []
        );
    }

    /**
     * Create a ResourceTemplateDefinition from reflection data.
     *
     * @param  ReflectionMethod  $method  The reflection method marked with McpResourceTemplate.
     * @param  McpResourceTemplate  $attribute  The attribute instance.
     * @param  DocBlockParser  $docBlockParser  Utility to parse docblocks.
     */
    public static function fromReflection(
        ReflectionMethod $method,
        McpResourceTemplate $attribute,
        DocBlockParser $docBlockParser
    ): self {
        $docBlock = $docBlockParser->parseDocBlock($method->getDocComment() ?: null);
        $description = $attribute->description ?? $docBlockParser->getSummary($docBlock) ?? null;

        return new self(
            className: $method->getDeclaringClass()->getName(),
            methodName: $method->getName(),
            uriTemplate: $attribute->uriTemplate,
            name: $attribute->name ?? $method->getName(),
            description: $description,
            mimeType: $attribute->mimeType,
            annotations: $attribute->annotations ?? []
        );
    }
}
