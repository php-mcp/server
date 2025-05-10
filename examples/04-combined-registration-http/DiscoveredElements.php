<?php

namespace Mcp\CombinedHttpExample\Discovered;

use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpTool;

class DiscoveredElements
{
    /**
     * A tool discovered via attributes.
     *
     * @return string A status message.
     */
    #[McpTool(name: 'discovered_status_check')]
    public function checkSystemStatus(): string
    {
        return 'System status: OK (discovered)';
    }

    /**
     * A resource discovered via attributes.
     * This will be overridden by a manual registration with the same URI.
     *
     * @return string Content.
     */
    #[McpResource(uri: 'config://priority', name: 'priority_config_discovered')]
    public function getPriorityConfigDiscovered(): string
    {
        return 'Discovered Priority Config: Low';
    }
}
