<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

class ConstructorStub {
    #[McpTool(name: 'constructor-tool')] // Should be ignored
    public function __construct() {}
} 