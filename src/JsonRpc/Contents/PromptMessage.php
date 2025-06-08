<?php

namespace PhpMcp\Server\JsonRpc\Contents;

use PhpMcp\Server\Model\Role;

/**
 * Describes a message returned as part of a prompt.
 */
class PromptMessage
{
    /**
     * Create a new PromptMessage instance.
     *
     * @param  Role  $role  Either "user" or "assistant"
     * @param  TextContent|ImageContent|AudioContent|EmbeddedResource  $content  The content of the message
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent|EmbeddedResource $content
    ) {
    }

    /**
     * Convert the message to an array.
     *
     * @return array{role: Role, content: array}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
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
        return new static(Role::User, new TextContent($text));
    }

    /**
     * Create a new assistant message with text content.
     *
     * @param  string  $text  The message text
     */
    public static function assistant(string $text): static
    {
        return new static(Role::Assistant, new TextContent($text));
    }

    /**
     * Create a new user message with any content type.
     *
     * @param  Content  $content  The message content
     */
    public static function userWithContent(TextContent|ImageContent|AudioContent|EmbeddedResource $content): static
    {
        return new static(Role::User, $content);
    }

    /**
     * Create a new assistant message with any content type.
     *
     * @param  Content  $content  The message content
     */
    public static function assistantWithContent(TextContent|ImageContent|AudioContent|EmbeddedResource $content): static
    {
        return new static(Role::Assistant, $content);
    }
}
