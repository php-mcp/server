<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\McpResource;

class MixedValidityStub
{
    #[McpTool(name: 'valid-tool')]
    public function validToolMethod(): void
    {
    }

    #[McpResource(uri: 'invalid uri pattern')] // Invalid URI
    public function invalidResourceMethod(): string
    {
        return '';
    }

    #[McpTool(name: 'another-valid-tool')]
    public function anotherValidToolMethod(): void
    {
    }
}
