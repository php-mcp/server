<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

class ToolOnlyStub
{
    #[McpTool(name: 'tool-from-file1')]
    public function tool1(): void
    {
    }
}
