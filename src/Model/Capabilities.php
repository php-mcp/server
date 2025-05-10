<?php

declare(strict_types=1);

namespace PhpMcp\Server\Model;

use stdClass;

/**
 * Represents the capabilities declared by the MCP Server.
 * This object should be constructed using the `Capabilities::forServer()` factory method.
 */
class Capabilities
{
    /**
     * Private constructor to enforce usage of the factory method.
     * Properties remain public readonly for easy access.
     */
    private function __construct(
        public readonly bool $toolsEnabled = true,
        public readonly bool $toolsListChanged = false,
        public readonly bool $resourcesEnabled = true,
        public readonly bool $resourcesSubscribe = false,
        public readonly bool $resourcesListChanged = false,
        public readonly bool $promptsEnabled = true,
        public readonly bool $promptsListChanged = false,
        public readonly bool $loggingEnabled = false,
        public readonly ?string $instructions = null,
        public readonly ?array $experimental = null
    ) {
    }

    /**
     * Factory method to create a Capabilities instance for the server.
     *
     * @param  bool  $toolsEnabled  Whether the tools capability is generally enabled.
     * @param  bool  $toolsListChanged  Whether the server supports 'tools/listChanged' notifications.
     * @param  bool  $resourcesEnabled  Whether the resources capability is generally enabled.
     * @param  bool  $resourcesSubscribe  Whether the server supports 'resources/subscribe'.
     * @param  bool  $resourcesListChanged  Whether the server supports 'resources/listChanged' notifications.
     * @param  bool  $promptsEnabled  Whether the prompts capability is generally enabled.
     * @param  bool  $promptsListChanged  Whether the server supports 'prompts/listChanged' notifications.
     * @param  bool  $loggingEnabled  Whether the server supports 'logging/setLevel'.
     * @param  string|null  $instructions  Optional static instructions text provided during initialization.
     * @param  array<string, mixed>|null  $experimental  Optional experimental capabilities declared by the server.
     */
    public static function forServer(
        bool $toolsEnabled = true,
        bool $toolsListChanged = false,
        bool $resourcesEnabled = true,
        bool $resourcesSubscribe = false,
        bool $resourcesListChanged = false,
        bool $promptsEnabled = true,
        bool $promptsListChanged = false,
        bool $loggingEnabled = false,
        ?string $instructions = null,
        ?array $experimental = null
    ): self {
        return new self(
            toolsEnabled: $toolsEnabled,
            toolsListChanged: $toolsListChanged,
            resourcesEnabled: $resourcesEnabled,
            resourcesSubscribe: $resourcesSubscribe,
            resourcesListChanged: $resourcesListChanged,
            promptsEnabled: $promptsEnabled,
            promptsListChanged: $promptsListChanged,
            loggingEnabled: $loggingEnabled,
            instructions: $instructions,
            experimental: $experimental
        );
    }

    /**
     * Converts server capabilities to the array format expected in the
     * 'initialize' response payload. Returns stdClass if all are disabled/default.
     */
    public function toInitializeResponseArray(): array|stdClass
    {
        $data = [];

        // Only include capability keys if the main capability is enabled
        if ($this->toolsEnabled) {
            $data['tools'] = $this->toolsListChanged ? ['listChanged' => true] : new stdClass();
        }
        if ($this->resourcesEnabled) {
            $resCaps = [];
            if ($this->resourcesSubscribe) {
                $resCaps['subscribe'] = true;
            }
            if ($this->resourcesListChanged) {
                $resCaps['listChanged'] = true;
            }
            $data['resources'] = ! empty($resCaps) ? $resCaps : new stdClass();
        }
        if ($this->promptsEnabled) {
            $data['prompts'] = $this->promptsListChanged ? ['listChanged' => true] : new stdClass();
        }
        if ($this->loggingEnabled) {
            $data['logging'] = new stdClass();
        }
        if ($this->experimental !== null && ! empty($this->experimental)) {
            $data['experimental'] = $this->experimental;
        }

        // Return empty object if no capabilities are effectively enabled/declared
        // This might deviate slightly from spec if e.g. only 'tools' is true but listChanged is false,
        // spec implies {'tools': {}} should still be sent. Let's keep it simple for now.
        // Correction: Spec implies the key should exist if the capability is enabled.
        // Let's ensure keys are present if the *Enabled flag is true.
        return empty($data) ? new stdClass() : $data;
    }
}
