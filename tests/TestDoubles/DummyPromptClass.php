<?php

namespace PhpMcp\Server\Tests\TestDoubles;

class DummyPromptClass
{
    /**
     * A greeting prompt generator
     */
    public function getGreetingPrompt(string $name, string $language = 'en'): array
    {
        return [
            ['role' => 'system', 'content' => 'Be polite and friendly'],
            ['role' => 'user', 'content' => "Greet {$name} in {$language}"]
        ];
    }

    /**
     * A simple prompt with no arguments
     */
    public function getSimplePrompt(): array
    {
        return [
            ['role' => 'system', 'content' => 'You are a helpful assistant'],
            ['role' => 'user', 'content' => 'Tell me about PHP']
        ];
    }

    /**
     * A prompt that generates error for testing
     */
    public function getErrorPrompt(): array
    {
        throw new \RuntimeException('Failed to generate prompt');
    }
}
