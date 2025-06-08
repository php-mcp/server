<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class CallToolResult implements ResultInterface
{
    /**
     * Create a new CallToolResult.
     *
     * @param  Content[]  $content  The content of the tool result
     * @param  bool  $isError  Whether the tool execution resulted in an error
     */
    public function __construct(
        public readonly array $content,
        public readonly bool $isError = false
    ) {
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

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
