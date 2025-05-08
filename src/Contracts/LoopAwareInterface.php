<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

use React\EventLoop\LoopInterface;

/**
 * Interface for components that require a ReactPHP event loop instance.
 *
 * Primarily used for injecting the configured loop into transport implementations.
 */
interface LoopAwareInterface
{
    public function setLoop(LoopInterface $loop): void;
}
