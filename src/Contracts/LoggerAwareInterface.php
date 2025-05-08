<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

use Psr\Log\LoggerInterface;

/**
 * Interface for components that can accept a PSR-3 Logger instance.
 *
 * Primarily used for injecting the configured logger into transport implementations.
 */
interface LoggerAwareInterface
{
    public function setLogger(LoggerInterface $logger): void;
}
