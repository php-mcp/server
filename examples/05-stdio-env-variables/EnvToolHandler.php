<?php

namespace Mcp\EnvExample;

use PhpMcp\Server\Attributes\McpTool;

class EnvToolHandler
{
    public function __construct()
    {
    }

    /**
     * Performs an action that can be modified by an environment variable.
     * The MCP client should set 'APP_MODE' in its 'env' config for this server.
     *
     * @param  string  $input  Some input data.
     * @return array The result, varying by APP_MODE.
     */
    #[McpTool(name: 'process_data_by_mode')]
    public function processData(string $input): array
    {
        $appMode = getenv('APP_MODE'); // Read from environment

        if ($appMode === 'debug') {
            return [
                'mode' => 'debug',
                'processed_input' => strtoupper($input),
                'message' => 'Processed in DEBUG mode.',
            ];
        } elseif ($appMode === 'production') {
            return [
                'mode' => 'production',
                'processed_input_length' => strlen($input),
                'message' => 'Processed in PRODUCTION mode (summary only).',
            ];
        } else {
            return [
                'mode' => $appMode ?: 'default',
                'original_input' => $input,
                'message' => 'Processed in default mode (APP_MODE not recognized or not set).',
            ];
        }
    }
}
