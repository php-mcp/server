<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\McpResourceTemplate;

class AllElementsStub
{
    #[McpTool(name: 'discovered-tool')]
    public function toolMethod(): void
    {
    }

    #[McpResource(uri: 'discovered://resource')]
    public function resourceMethod(): string
    {
        return '';
    }

    #[McpPrompt(name: 'discovered-prompt')]
    public function promptMethod(): array
    {
        return [];
    }

    #[McpResourceTemplate(uriTemplate: 'discovered://template/{id}')]
    public function templateMethod(string $id): array
    {
        return [];
    }

    // Non-MCP method
    public function ignoredMethod(): void
    {
    }
}
