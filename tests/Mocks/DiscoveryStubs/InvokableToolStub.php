<?php

namespace PhpMcp\Server\Tests\Mocks\DiscoveryStubs;

use PhpMcp\Server\Attributes\McpTool;

/**
 * An invokable class with an McpTool attribute.
 */
#[McpTool(name: 'invokable-tool', description: 'An invokable tool stub')]
class InvokableToolStub
{
    /**
     * The invokable method.
     *
     * @param  string  $arg  A required argument.
     * @return string The result.
     */
    public function __invoke(string $arg): string
    {
        return 'Invoked tool with: '.$arg;
    }
}
