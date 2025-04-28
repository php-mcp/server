<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

abstract class AbstractStub {
    #[McpTool(name: 'tool-in-abstract')] // Should be ignored
    public abstract function doWork();
} 