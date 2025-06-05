<?php

namespace PhpMcp\Server\Definitions;

use PhpMcp\Server\Support\DocBlockParser;

/**
 * Describes a prompt or prompt template.
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
     * @param  string  $promptName   The name of the prompt or prompt template.
     * @param  string|null  $description An optional description of what this prompt provides
     * @param  PromptArgumentDefinition[]  $arguments  A list of arguments to use for templating the prompt. Empty if not a template.
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
                "Prompt name '{$this->promptName}' is invalid. Prompt names must match the pattern " . self::PROMPT_NAME_PATTERN
                    . ' (alphanumeric characters, underscores, and hyphens only).'
            );
        }
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
                fn(PromptArgumentDefinition $arg) => $arg->toArray(),
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
        ?string $overrideName,
        ?string $overrideDescription,
        DocBlockParser $docBlockParser
    ): self {
        $className = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        $promptName = $overrideName ?? ($methodName === '__invoke' ? $method->getDeclaringClass()->getShortName() : $methodName);
        $docBlock = $docBlockParser->parseDocBlock($method->getDocComment() ?: null);
        $description = $overrideDescription ?? $docBlockParser->getSummary($docBlock) ?? null;

        $arguments = [];
        $paramTags = $docBlockParser->getParamTags($docBlock);
        foreach ($method->getParameters() as $param) {
            $reflectionType = $param->getType();

            if ($reflectionType instanceof \ReflectionNamedType && ! $reflectionType->isBuiltin()) {
                continue;
            }

            $paramTag = $paramTags['$' . $param->getName()] ?? null;
            $arguments[] = PromptArgumentDefinition::fromReflection($param, $paramTag);
        }

        return new self(
            className: $className,
            methodName: $methodName,
            promptName: $promptName,
            description: $description,
            arguments: $arguments
        );
    }
}
