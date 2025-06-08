<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class CompletionCompleteResult implements ResultInterface
{
    /**
     * @param string[] $values Array of completion suggestions.
     * @param int|null $total Optional total number of available matches.
     * @param bool|null $hasMore Optional flag indicating if more results exist.
     */
    public function __construct(
        public readonly array $values,
        public readonly ?int $total = null,
        public readonly ?bool $hasMore = null
    ) {
    }

    public function toArray(): array
    {
        $result = ['completion' => ['values' => $this->values]];
        if ($this->total !== null) {
            $result['completion']['total'] = $this->total;
        }
        if ($this->hasMore !== null) {
            $result['completion']['hasMore'] = $this->hasMore;
        }
        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
