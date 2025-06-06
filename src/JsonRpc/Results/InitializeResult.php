<?php

namespace PhpMcp\Server\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Contracts\ResultInterface;

class InitializeResult implements ResultInterface
{
    /**
     * Create a new InitializeResult.
     *
     * @param  array  $serverInfo  Server information
     * @param  string  $protocolVersion  Protocol version
     * @param  array  $capabilities  Server capabilities
     * @param  string|null  $instructions  Optional instructions text
     */
    public function __construct(
        public readonly array $serverInfo,
        public readonly string $protocolVersion,
        public readonly array $capabilities,
        public readonly ?string $instructions = null
    ) {}

    /**
     * Convert the result to an array.
     */
    public function toArray(): array
    {
        $result = [
            'serverInfo' => $this->serverInfo,
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
