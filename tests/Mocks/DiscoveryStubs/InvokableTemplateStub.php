<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpResourceTemplate;

/**
 * An invokable class with an McpResourceTemplate attribute.
 */
#[McpResourceTemplate(uriTemplate: 'invokable://template/{id}', name: 'invokable-template', mimeType: 'application/json')]
class InvokableTemplateStub
{
    /**
     * The invokable method generating resource content from template.
     *
     * @param  string  $id  The ID from the URI template.
     * @return array The resource data.
     */
    public function __invoke(string $id): array
    {
        return ['id' => $id, 'data' => 'Invoked template data'];
    }
}
