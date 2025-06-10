<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

class ToolOnlyStub
{
    public function __invoke(): void
    {
    }

    #[McpTool(name: 'tool-from-file1')]
    public function tool1(): void
    {
    }
}
