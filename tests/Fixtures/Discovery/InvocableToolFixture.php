<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\McpResourceTemplate;

#[McpTool(name: "InvokableCalculator", description: "An invokable calculator tool.")]
class InvocableToolFixture
{
    /**
     * Adds two numbers.
     * @param int $a
     * @param int $b
     * @return int
     */
    public function __invoke(int $a, int $b): int
    {
        return $a + $b;
    }
}
