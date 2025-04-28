<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contents\PromptMessage;
use PhpMcp\Server\JsonRpc\Result;

class GetPromptResult extends Result
{
    /**
     * Create a new GetPromptResult.
     *
     * @param  PromptMessage[]  $messages  The messages in the prompt
     * @param  string|null  $description  Optional description of the prompt
     */
    public function __construct(
        protected array $messages,
        protected ?string $description = null
    ) {
    }

    /**
     * Get the messages in the prompt.
     *
     * @return PromptMessage[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get the description of the prompt.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        $result = [
            'messages' => array_map(fn ($message) => $message->toArray(), $this->messages),
        ];

        if ($this->description !== null) {
            $result['description'] = $this->description;
        }

        return $result;
    }
}
