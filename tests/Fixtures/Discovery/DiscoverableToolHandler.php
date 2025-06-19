<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedStringEnum;

class DiscoverableToolHandler
{
    /**
     * A basic discoverable tool.
     * @param string $name The name to greet.
     * @return string The greeting.
     */
    #[McpTool(name: "greet_user", description: "Greets a user by name.")]
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }

    /**
     * A tool with more complex parameters and inferred name/description.
     * @param int $count The number of times to repeat.
     * @param bool $loudly Should it be loud?
     * @param BackedStringEnum $mode The mode of operation.
     * @return array An array with results.
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function repeatAction(int $count, bool $loudly = false, BackedStringEnum $mode = BackedStringEnum::OptionA): array
    {
        return ['count' => $count, 'loudly' => $loudly, 'mode' => $mode->value, 'message' => "Action repeated."];
    }

    // This method should NOT be discovered as a tool
    public function internalHelperMethod(int $value): int
    {
        return $value * 2;
    }

    #[McpTool(name: "private_tool_should_be_ignored")] // On private method
    private function aPrivateTool(): void
    {
    }

    #[McpTool(name: "protected_tool_should_be_ignored")] // On protected method
    protected function aProtectedTool(): void
    {
    }

    #[McpTool(name: "static_tool_should_be_ignored")] // On static method
    public static function aStaticTool(): void
    {
    }
}
