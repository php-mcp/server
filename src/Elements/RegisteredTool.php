<?php

declare(strict_types=1);

namespace PhpMcp\Server\Elements;

use PhpMcp\Schema\Content\Content;
use PhpMcp\Schema\Content\TextContent;
use Psr\Container\ContainerInterface;
use PhpMcp\Schema\Tool;

class RegisteredTool extends RegisteredElement
{
    public function __construct(
        public readonly Tool $schema,
        string $handlerClass,
        string $handlerMethod,
        bool $isManual = false,
    ) {
        parent::__construct($handlerClass, $handlerMethod, $isManual);
    }

    public static function make(Tool $schema, string $handlerClass, string $handlerMethod, bool $isManual = false): self
    {
        return new self($schema, $handlerClass, $handlerMethod, $isManual);
    }

    /**
     * Calls the underlying handler for this tool.
     *
     * @return Content[] The content items for CallToolResult.
     */
    public function call(ContainerInterface $container, array $arguments): array
    {
        $result = $this->handle($container, $arguments);

        return $this->formatResult($result);
    }

    /**
     * Formats the result of a successful tool execution into the MCP CallToolResult structure.
     *
     * @param  mixed  $toolExecutionResult  The raw value returned by the tool's PHP method.
     * @return Content[] The content items for CallToolResult.
     *
     * @throws JsonException if JSON encoding fails
     */
    protected function formatResult(mixed $toolExecutionResult): array
    {
        if (is_array($toolExecutionResult) && ! empty($toolExecutionResult) && $toolExecutionResult[array_key_first($toolExecutionResult)] instanceof Content) {
            return $toolExecutionResult;
        }

        if ($toolExecutionResult instanceof Content) {
            return [$toolExecutionResult];
        }

        if ($toolExecutionResult === null) {
            return [TextContent::make('(null)')];
        }

        if (is_bool($toolExecutionResult)) {
            return [TextContent::make($toolExecutionResult ? 'true' : 'false')];
        }

        if (is_scalar($toolExecutionResult)) {
            return [TextContent::make($toolExecutionResult)];
        }

        $jsonResult = json_encode(
            $toolExecutionResult,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return [TextContent::make($jsonResult)];
    }
}
