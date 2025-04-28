<?php

namespace PhpMcp\Server\Definitions;

use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Support\DocBlockParser;

/**
 * Represents a discovered MCP Prompt or Prompt Template.
 * Aligns with MCP 'Prompt' structure for listing and 'GetPromptResult' for getting.
 */
class PromptDefinition
{
    /**
     * Prompt name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     * This matches the same pattern as used for tool names.
     */
    private const PROMPT_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * @param  class-string  $className  The fully qualified class name containing the prompt generation logic.
     * @param  string  $methodName  The name of the PHP method implementing the prompt generation.
     * @param  string  $promptName  The designated name of the MCP prompt (used in 'prompts/get').
     * @param  string|null  $description  A description of what this prompt provides.
     * @param  PromptArgumentDefinition[]  $arguments  Definitions of arguments used for templating. Empty if not a template.
     *
     * @throws \InvalidArgumentException If the prompt name doesn't match the required pattern.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $promptName,
        public readonly ?string $description,
        public readonly array $arguments = []
    ) {
        $this->validate();
    }

    /**
     * Validates the definition parameters
     *
     * @throws \InvalidArgumentException If the prompt name is invalid
     */
    private function validate(): void
    {
        if (! preg_match(self::PROMPT_NAME_PATTERN, $this->promptName)) {
            throw new \InvalidArgumentException(
                "Prompt name '{$this->promptName}' is invalid. Prompt names must match the pattern ".self::PROMPT_NAME_PATTERN
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

    public function getName(): string
    {
        return $this->promptName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<PromptArgumentDefinition>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function isTemplate(): bool
    {
        return ! empty($this->arguments);
    }

    /**
     * Formats the definition into the structure expected by MCP's 'prompts/list'.
     *
     * @return array{name: string, description?: string, arguments?: list<array{name: string, description?: string, required?: bool}>}
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->promptName,
        ];
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if (! empty($this->arguments)) {
            $data['arguments'] = array_map(
                fn (PromptArgumentDefinition $arg) => $arg->toArray(),
                $this->arguments
            );
        }

        return $data;
    }

    /**
     * Reconstruct a PromptDefinition from its array representation.
     *
     * @param  array  $data  The array representation of a PromptDefinition
     * @return static The reconstructed PromptDefinition
     */
    public static function fromArray(array $data): static
    {
        $arguments = [];
        if (isset($data['arguments']) && is_array($data['arguments'])) {
            foreach ($data['arguments'] as $argData) {
                $arguments[] = PromptArgumentDefinition::fromArray($argData);
            }
        }

        return new self(
            className: $data['className'],
            methodName: $data['methodName'],
            promptName: $data['promptName'],
            description: $data['description'],
            arguments: $arguments
        );
    }

    /**
     * Create a PromptDefinition from reflection data.
     */
    public static function fromReflection(
        \ReflectionMethod $method,
        McpPrompt $attribute,
        DocBlockParser $docBlockParser
    ): self {
        $className = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        $docBlock = $docBlockParser->parseDocBlock($method->getDocComment() ?: null);
        $description = $attribute->description ?? $docBlockParser->getSummary($docBlock) ?? null;

        $arguments = [];
        $paramTags = $docBlockParser->getParamTags($docBlock); // Get all param tags first
        foreach ($method->getParameters() as $param) {
            $reflectionType = $param->getType();

            // Basic DI check (heuristic)
            if ($reflectionType instanceof \ReflectionNamedType && ! $reflectionType->isBuiltin()) {
                continue;
            }

            // Correctly get the specific Param tag using the '$' prefix
            $paramTag = $paramTags['$'.$param->getName()] ?? null;
            $arguments[] = PromptArgumentDefinition::fromReflection($param, $paramTag);
        }

        return new self(
            className: $className,
            methodName: $methodName,
            promptName: $attribute->name ?? $methodName,
            description: $description,
            arguments: $arguments
        );
    }
}
