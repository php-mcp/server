<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

class PrivateMethodStub
{
    #[McpTool(name: 'private-tool')] // Should be ignored
    private function work()
    {
    }
}
