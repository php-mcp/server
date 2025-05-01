<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpPrompt;

/**
 * An invokable class with an McpPrompt attribute.
 */
#[McpPrompt(name: 'invokable-prompt', description: 'An invokable prompt stub')]
class InvokablePromptStub
{
    /**
     * The invokable method generating prompt messages.
     *
     * @param  string  $topic  The topic for the prompt.
     * @return array The prompt messages.
     */
    public function __invoke(string $topic): array
    {
        return [
            ['role' => 'user', 'content' => "Generate something about {$topic}"],
        ];
    }
}
