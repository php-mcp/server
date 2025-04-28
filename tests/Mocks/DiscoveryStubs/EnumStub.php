<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

enum EnumStub {
    case A;
    // Methods in enums might be static or instance, check filtering
    #[McpTool(name: 'tool-in-enum')]
    public function work() {} // Should be ignored as enum itself isn't scanned for instance methods like this
} 