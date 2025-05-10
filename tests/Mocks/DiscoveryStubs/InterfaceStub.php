<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

interface InterfaceStub
{
    // Attributes on interface methods are irrelevant
    #[McpTool(name: 'tool-in-interface')]
    public function work();
}
