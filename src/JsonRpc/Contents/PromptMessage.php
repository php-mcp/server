<?php

namespace PhpMcp\Server\JsonRpc\Contents;

/**
 * Represents a message in an MCP prompt.
 */
class PromptMessage
{
    /**
     * Create a new PromptMessage instance.
     *
     * @param  string  $role  Either "user" or "assistant"
     * @param  Content  $content  The content of the message
     */
    public function __construct(
        protected string $role,
        protected Content $content
    ) {
        // Validate role
        if (! in_array($role, ['user', 'assistant'])) {
            throw new \InvalidArgumentException('Role must be either "user" or "assistant".');
        }
    }

    /**
     * Get the role.
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Get the content.
     */
    public function getContent(): Content
    {
        return $this->content;
    }

    /**
     * Convert the message to an array.
     *
     * @return array{role: string, content: array}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content->toArray(),
        ];
    }

    /**
     * Create a new user message with text content.
     *
     * @param  string  $text  The message text
     */
    public static function user(string $text): static
    {
        return new static('user', new TextContent($text));
    }

    /**
     * Create a new assistant message with text content.
     *
     * @param  string  $text  The message text
     */
    public static function assistant(string $text): static
    {
        return new static('assistant', new TextContent($text));
    }

    /**
     * Create a new user message with any content type.
     *
     * @param  Content  $content  The message content
     */
    public static function userWithContent(Content $content): static
    {
        return new static('user', $content);
    }

    /**
     * Create a new assistant message with any content type.
     *
     * @param  Content  $content  The message content
     */
    public static function assistantWithContent(Content $content): static
    {
        return new static('assistant', $content);
    }
}
