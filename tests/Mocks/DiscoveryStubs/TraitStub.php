<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

trait TraitStub {
    #[McpTool(name: 'tool-in-trait')] // Should be ignored when processing the trait file directly
    public function work() {}
} 