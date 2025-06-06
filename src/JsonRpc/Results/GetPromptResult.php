<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contents\PromptMessage;
use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class GetPromptResult implements ResultInterface
{
    /**
     * Create a new GetPromptResult.
     *
     * @param  PromptMessage[]  $messages  The messages in the prompt
     * @param  string|null  $description  Optional description of the prompt
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $description = null
    ) {}

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        $result = [
            'messages' => array_map(fn($message) => $message->toArray(), $this->messages),
        ];

        if ($this->description !== null) {
            $result['description'] = $this->description;
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
