<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs; 

use PhpMcp\Server\Attributes\McpTool;

class ParentWithTool {
     #[McpTool(name: 'parent-tool')] // This would be found if ParentWithTool is scanned
     public function parentWork() {}
} 