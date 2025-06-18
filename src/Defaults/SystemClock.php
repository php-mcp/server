<?php

declare(strict_types=1);

namespace PhpMcp\Server\Defaults;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
