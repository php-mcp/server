<?php

namespace PhpMcp\Server\Definitions;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Support\SchemaGenerator;
use ReflectionMethod;

/**
 * Represents a discovered MCP Tool.
 */
class ToolDefinition
{
    /**
     * Tool name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     */
    private const TOOL_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * Available tool annotations as defined by the MCP specification,
     * and a validator function for each.
     */
    private const VALID_ANNOTATION_KEYS = [
        'title' => 'is_string',
        'readOnlyHint' => 'is_bool',
        'destructiveHint' => 'is_bool',
        'idempotentHint' => 'is_bool',
        'openWorldHint' => 'is_bool',

    ];

    /**
     * @param  class-string  $className  The fully qualified class name containing the tool method.
     * @param  string  $methodName  The name of the PHP method implementing the tool.
     * @param  string  $toolName  The designated name of the MCP tool (used in 'tools/call' requests).
     * @param  string|null  $description  A human-readable description of the tool.
     * @param  array<string, mixed>  $inputSchema  A JSON Schema object (as a PHP array) defining the expected 'arguments' for the tool. Complies with MCP 'Tool.inputSchema'.
     * @param  array<string, mixed>  $annotations  Optional annotations (title, readOnlyHint, destructiveHint).
     *
     * @throws \InvalidArgumentException If the tool name doesn't match the required pattern.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $toolName,
        public readonly ?string $description,
        public readonly array $inputSchema,
        public readonly array $annotations = [],
    ) {
        $this->validate();
    }

    /**
     * Validates the definition parameters
     *
     * @throws \InvalidArgumentException If the tool name is invalid
     */
    private function validate(): void
    {
        if (! preg_match(self::TOOL_NAME_PATTERN, $this->toolName)) {
            throw new \InvalidArgumentException(
                "Tool name '{$this->toolName}' is invalid. Tool names must match the pattern " . self::TOOL_NAME_PATTERN
                    . ' (alphanumeric characters, underscores, and hyphens only).'
            );
        }

        foreach ($this->annotations as $key => $value) {
            // Check there are no invalid annotations keys
            if (! array_key_exists($key, self::VALID_ANNOTATION_KEYS)) {
                throw new \InvalidArgumentException("Invalid annotation key '{$key}' in tool '{$this->toolName}'.");
            }

            // Check annotations values match defined types
            $validator = self::VALID_ANNOTATION_KEYS[$key];
            if (! $validator($value)) {
                throw new \InvalidArgumentException(
                    "Annotation '{$key}' for tool '{$this->toolName}' must be of type "
                    . gettype($value) . ', expected ' . gettype($validator(null))
                );
            }
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

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Gets the JSON schema defining the tool's input arguments.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnnotations(): array
    {
        return $this->annotations;
    }

    /**
     * Convert the tool definition to MCP format.
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->toolName,
        ];

        if ($this->description) {
            $result['description'] = $this->description;
        }

        if ($this->inputSchema) {
            $result['inputSchema'] = $this->inputSchema;
        }

        if ($this->annotations) {
            $result['annotations'] = $this->annotations;
        }

        return $result;
    }

    /**
     * Reconstruct a ToolDefinition from its array representation.
     *
     * @param  array  $data  The array representation of a ToolDefinition
     * @return static The reconstructed ToolDefinition
     */
    public static function fromArray(array $data): static
    {
        return new self(
            className: $data['className'],
            methodName: $data['methodName'],
            toolName: $data['toolName'],
            description: $data['description'],
            inputSchema: $data['inputSchema'],
            annotations: $data['annotations'] ?? []
        );
    }

    /**
     * Create a ToolDefinition from reflection data.
     *
     * @param  ReflectionMethod  $method  The reflection method for the tool.
     * @param  McpTool  $attribute  The attribute instance.
     * @param  DocBlockParser  $docBlockParser  Utility to parse docblocks.
     * @param  SchemaGenerator  $schemaGenerator  Utility to generate JSON schema.
     */
    public static function fromReflection(
        ReflectionMethod $method,
        ?string $overrideName,
        ?string $overrideDescription,
        ?array $overrideAnnotations,
        DocBlockParser $docBlockParser,
        SchemaGenerator $schemaGenerator
    ): self {
        $docBlock = $docBlockParser->parseDocBlock($method->getDocComment() ?? null);
        $description = $overrideDescription ?? $docBlockParser->getSummary($docBlock) ?? null;
        $inputSchema = $schemaGenerator->fromMethodParameters($method);
        $annotations = $overrideAnnotations ?? null;

        $toolName = $overrideName ?? ($method->getName() === '__invoke'
            ? $method->getDeclaringClass()->getShortName()
            : $method->getName());

        return new self(
            className: $method->getDeclaringClass()->getName(),
            methodName: $method->getName(),
            toolName: $toolName,
            description: $description,
            inputSchema: $inputSchema,
            annotations: $annotations ?? []
        );
    }
}
