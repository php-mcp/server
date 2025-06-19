<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Schema\Annotations;
use PhpMcp\Server\Attributes\McpResource;

class DiscoverableResourceHandler
{
    /**
     * Provides the application's current version.
     * @return string The version string.
     */
    #[McpResource(
        uri: "app://info/version",
        name: "app_version",
        description: "The current version of the application.",
        mimeType: "text/plain",
        size: 10
    )]
    public function getAppVersion(): string
    {
        return "1.2.3-discovered";
    }

    #[McpResource(
        uri: "config://settings/ui",
        name: "ui_settings_discovered",
        mimeType: "application/json",
        annotations: new Annotations(priority: 0.5)
    )]
    public function getUiSettings(): array
    {
        return ["theme" => "dark", "fontSize" => 14];
    }

    public function someOtherMethod(): void
    {
    }
}
