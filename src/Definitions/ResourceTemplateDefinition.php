<?php

namespace PhpMcp\Server\Definitions;

use PhpMcp\Server\Model\Annotations;
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
     * @param  ?Annotations  $annotations  Optional annotations describing the resource template.
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
        public readonly ?Annotations $annotations,
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
                    . self::URI_TEMPLATE_PATTERN . ' (valid scheme followed by :// and path with placeholder(s) in curly braces).'
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
     * Formats the definition into the structure expected by MCP's 'resources/templates/list'.
     *
     * @return array{uriTemplate: string, name: string, description?: string, mimeType?: string, annotations?: array}
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
        if ($this->annotations !== null) {
            $data['annotations'] = $this->annotations->toArray();
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
        $annotations = isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null;
        return new self(
            className: $data['className'],
            methodName: $data['methodName'],
            uriTemplate: $data['uriTemplate'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: $annotations,
        );
    }

    /**
     * Create a ResourceTemplateDefinition from reflection data.
     *
     * @param  ReflectionMethod  $method  The reflection method marked with McpResourceTemplate.
     * @param  string|null  $overrideName  The name for the resource.
     * @param  string|null  $overrideDescription  The description for the resource.
     * @param  string  $uriTemplate  The URI template for the resource.
     * @param  string|null  $mimeType  The MIME type for the resource.
     * @param  DocBlockParser  $docBlockParser  Utility to parse docblocks.
     */
    public static function fromReflection(
        ReflectionMethod $method,
        ?string $overrideName,
        ?string $overrideDescription,
        string $uriTemplate,
        ?string $mimeType,
        ?Annotations $annotations,
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
            uriTemplate: $uriTemplate,
            name: $name,
            description: $description,
            mimeType: $mimeType,
            annotations: $annotations,
        );
    }
}
