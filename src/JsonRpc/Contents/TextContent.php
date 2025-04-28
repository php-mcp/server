<?php

namespace PhpMcp\Server\JsonRpc\Contents;

/**
 * Represents text content in MCP.
 */
class TextContent extends Content
{
    /**
     * Create a new TextContent instance.
     *
     * @param  string  $text  The text content
     */
    public function __construct(
        protected string $text
    ) {
    }

    /**
     * Get the text content.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Get the content type.
     */
    public function getType(): string
    {
        return 'text';
    }

    /**
     * Convert the content to an array.
     *
     * @return array{type: string, text: string}
     */
    public function toArray(): array
    {
        return [
            'type' => 'text',
            'text' => $this->text,
        ];
    }

    /**
     * Create a new TextContent from any simple value.
     *
     * @param  mixed  $value  The value to convert to text
     */
    public static function make(mixed $value): static
    {
        if (is_array($value) || is_object($value)) {
            $text = json_encode($value, JSON_PRETTY_PRINT);

            return new static($text);
        }

        return new static((string) $value);
    }

    /**
     * Create a new TextContent with markdown formatted code.
     *
     * @param  string  $code  The code to format
     * @param  string  $language  The language for syntax highlighting
     */
    public static function code(string $code, string $language = ''): static
    {
        return new static("```{$language}\n{$code}\n```");
    }
}
