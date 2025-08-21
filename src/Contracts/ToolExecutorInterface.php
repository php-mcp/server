<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

use PhpMcp\Server\Context;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Server\Exception\ValidationException;

interface ToolExecutorInterface
{
    /**
     * Call a registered tool with the given arguments.
     *
     * @throws ValidationException If arguments do not match the tool's input schema.
     */
    public function call(RegisteredTool $registeredTool, array $arguments, Context $context): array;
}
