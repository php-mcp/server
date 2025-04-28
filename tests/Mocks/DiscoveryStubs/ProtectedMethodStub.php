<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

class ProtectedMethodStub {
    #[McpTool(name: 'protected-tool')] // Should be ignored
    protected function work() {}
} 