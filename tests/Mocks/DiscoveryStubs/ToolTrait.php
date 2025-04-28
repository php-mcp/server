<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs; 

use PhpMcp\Server\Attributes\McpTool;

trait ToolTrait {
    #[McpTool(name: 'trait-tool')] // Should be discovered via the class using it
    public function doTraitWork() {}
} 