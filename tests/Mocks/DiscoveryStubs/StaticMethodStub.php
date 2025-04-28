<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

class StaticMethodStub {
    #[McpTool(name: 'static-tool')] // Should be ignored
    public static function work() {}
} 