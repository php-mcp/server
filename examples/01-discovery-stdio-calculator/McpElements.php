<?php

namespace Mcp\StdioCalculatorExample;

use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpTool;

class McpElements
{
    private array $config = [
        'precision' => 2,
        'allow_negative' => true,
    ];

    /**
     * Performs a calculation based on the operation.
     *
     * Supports 'add', 'subtract', 'multiply', 'divide'.
     * Obeys the 'precision' and 'allow_negative' settings from the config resource.
     *
     * @param  float  $a  The first operand.
     * @param  float  $b  The second operand.
     * @param  string  $operation  The operation ('add', 'subtract', 'multiply', 'divide').
     * @return float|string The result of the calculation, or an error message string.
     */
    #[McpTool(name: 'calculate')]
    public function calculate(float $a, float $b, string $operation): float|string
    {
        // Use STDERR for logs
        fwrite(STDERR, "Calculate tool called: a=$a, b=$b, op=$operation\n");

        $op = strtolower($operation);
        $result = null;

        switch ($op) {
            case 'add':
                $result = $a + $b;
                break;
            case 'subtract':
                $result = $a - $b;
                break;
            case 'multiply':
                $result = $a * $b;
                break;
            case 'divide':
                if ($b == 0) {
                    return 'Error: Division by zero.';
                }
                $result = $a / $b;
                break;
            default:
                return "Error: Unknown operation '{$operation}'. Supported: add, subtract, multiply, divide.";
        }

        if (! $this->config['allow_negative'] && $result < 0) {
            return 'Error: Negative results are disabled.';
        }

        return round($result, $this->config['precision']);
    }

    /**
     * Provides the current calculator configuration.
     * Can be read by clients to understand precision etc.
     *
     * @return array The configuration array.
     */
    #[McpResource(
        uri: 'config://calculator/settings',
        name: 'calculator_config',
        description: 'Current settings for the calculator tool (precision, allow_negative).',
        mimeType: 'application/json' // Return as JSON
    )]
    public function getConfiguration(): array
    {
        fwrite(STDERR, "Resource config://calculator/settings read.\n");

        return $this->config;
    }

    /**
     * Updates a specific configuration setting.
     * Note: This requires more robust validation in a real app.
     *
     * @param  string  $setting  The setting key ('precision' or 'allow_negative').
     * @param  mixed  $value  The new value (int for precision, bool for allow_negative).
     * @return array Success message or error.
     */
    #[McpTool(name: 'update_setting')]
    public function updateSetting(string $setting, mixed $value): array
    {
        fwrite(STDERR, "Update Setting tool called: setting=$setting, value=".var_export($value, true)."\n");
        if (! array_key_exists($setting, $this->config)) {
            return ['success' => false, 'error' => "Unknown setting '{$setting}'."];
        }

        if ($setting === 'precision') {
            if (! is_int($value) || $value < 0 || $value > 10) {
                return ['success' => false, 'error' => 'Invalid precision value. Must be integer between 0 and 10.'];
            }
            $this->config['precision'] = $value;

            // In real app, notify subscribers of config://calculator/settings change
            // $registry->notifyResourceChanged('config://calculator/settings');
            return ['success' => true, 'message' => "Precision updated to {$value}."];
        }

        if ($setting === 'allow_negative') {
            if (! is_bool($value)) {
                // Attempt basic cast for flexibility
                if (in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'])) {
                    $value = true;
                } elseif (in_array(strtolower((string) $value), ['false', '0', 'no', 'off'])) {
                    $value = false;
                } else {
                    return ['success' => false, 'error' => 'Invalid allow_negative value. Must be boolean (true/false).'];
                }
            }
            $this->config['allow_negative'] = $value;

            // $registry->notifyResourceChanged('config://calculator/settings');
            return ['success' => true, 'message' => 'Allow negative results set to '.($value ? 'true' : 'false').'.'];
        }

        return ['success' => false, 'error' => 'Internal error handling setting.']; // Should not happen
    }
}
