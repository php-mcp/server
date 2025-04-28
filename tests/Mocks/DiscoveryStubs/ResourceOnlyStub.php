<?php 
namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpResource;

class ResourceOnlyStub {
     #[McpResource(uri: 'res-from-file2')]
    public function resource2(): string { return ''; }
} 