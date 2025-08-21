<?php

declare(strict_types=1);

namespace PhpMcp\Server\Utils;

use PhpMcp\Server\Context;
use PhpMcp\Server\Contracts\ToolExecutorInterface;
use PhpMcp\Server\Elements\RegisteredTool;
use Psr\Container\ContainerInterface;

final class ToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SchemaValidator $schemaValidator,
    ) {
    }

    public function call(
        RegisteredTool $registeredTool,
        array $arguments,
        Context $context,
    ): array {
        $inputSchema = $registeredTool->schema->inputSchema;

        $this->schemaValidator->validateAgainstJsonSchema($arguments, $inputSchema);

        return $registeredTool->call($this->container, $arguments, $context);
    }
}
