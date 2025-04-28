<?php

namespace PhpMcp\Server\Definitions;

use phpDocumentor\Reflection\DocBlock\Tags\Param;
use ReflectionParameter;

/**
 * Represents a defined argument for an MCP Prompt template.
 * Compliant with MCP 'PromptArgument'.
 */
class PromptArgumentDefinition
{
    /**
     * @param  string  $name  The name of the argument.
     * @param  string|null  $description  A human-readable description of the argument.
     * @param  bool  $required  Whether this argument must be provided when getting the prompt. Defaults to false.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $required = false
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Formats the definition into the structure expected by MCP's 'Prompt.arguments'.
     *
     * @return array{name: string, description?: string, required?: bool}
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
        ];
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        // 'required' defaults to false, only include if true for brevity? Schema doesn't specify default. Let's include it.
        $data['required'] = $this->required;

        return $data;
    }

    /**
     * Reconstruct a PromptArgumentDefinition from its array representation.
     *
     * @param  array  $data  The array representation of a PromptArgumentDefinition
     * @return static The reconstructed PromptArgumentDefinition
     */
    public static function fromArray(array $data): static
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            required: $data['required'] ?? false
        );
    }

    /**
     * Create a PromptArgumentDefinition from reflection data.
     *
     * @param  \ReflectionParameter  $parameter  The reflection parameter.
     * @param  \phpDocumentor\Reflection\DocBlock\Tags\Param|null  $paramTag  The corresponding parsed @param tag, or null.
     */
    public static function fromReflection(ReflectionParameter $parameter, ?Param $paramTag = null): self
    {
        $name = $parameter->getName();
        $description = $paramTag ? trim((string) $paramTag->getDescription()) : null;

        return new self(
            name: $name,
            description: $description,
            required: ! $parameter->isOptional() && ! $parameter->isDefaultValueAvailable()
        );
    }
}
