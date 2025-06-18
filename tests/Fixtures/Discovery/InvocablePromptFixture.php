<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Server\Attributes\McpPrompt;

#[McpPrompt(name: "InvokableGreeterPrompt")]
class InvocablePromptFixture
{
    /**
     * @param string $personName
     * @return array
     */
    public function __invoke(string $personName): array
    {
        return [['role' => 'user', 'content' => "Generate a short greeting for {$personName}."]];
    }
}
