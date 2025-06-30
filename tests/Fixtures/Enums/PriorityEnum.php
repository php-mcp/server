<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Enums;

enum PriorityEnum: int
{
    case LOW = 1;
    case MEDIUM = 2;
    case HIGH = 3;
}
