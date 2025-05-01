<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpResource;

/**
 * An invokable class with an McpResource attribute.
 */
#[McpResource(uri: 'invokable://resource', name: 'invokable-resource', mimeType: 'text/plain')]
class InvokableResourceStub
{
    /**
     * The invokable method returning resource content.
     *
     * @return string The resource content.
     */
    public function __invoke(): string
    {
        return 'Invoked resource content.';
    }
}
