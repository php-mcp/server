<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class ListPromptsResult implements ResultInterface
{
    /**
     * @param  array<PromptDefinition>  $prompts  The list of prompt definitions.
     * @param  string|null  $nextCursor  The cursor for the next page, or null if this is the last page.
     */
    public function __construct(
        public readonly array $prompts,
        public readonly ?string $nextCursor = null
    ) {}

    public function toArray(): array
    {
        $result = [
            'prompts' => array_map(fn(PromptDefinition $p) => $p->toArray(), $this->prompts),
        ];

        if ($this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
