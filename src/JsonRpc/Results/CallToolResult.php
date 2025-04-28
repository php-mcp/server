<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contents\Content;
use PhpMcp\Server\JsonRpc\Result;

class CallToolResult extends Result
{
    /**
     * Create a new CallToolResult.
     *
     * @param  Content[]  $content  The content of the tool result
     * @param  bool  $isError  Whether the tool execution resulted in an error
     */
    public function __construct(
        protected array $content,
        protected bool $isError = false
    ) {
    }

    /**
     * Get the content of the tool result.
     *
     * @return Content[]
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * Check if the tool execution resulted in an error.
     */
    public function isError(): bool
    {
        return $this->isError;
    }

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        return [
            'content' => array_map(fn ($item) => $item->toArray(), $this->content),
            'isError' => $this->isError,
        ];
    }
}
