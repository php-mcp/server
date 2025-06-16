<?php

declare(strict_types=1);

namespace PhpMcp\Server\Utils;

use PhpMcp\Server\Contracts\IdGeneratorInterface;

class RandomIdGenerator implements IdGeneratorInterface
{
    public function generateId(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex characters
    }
}
