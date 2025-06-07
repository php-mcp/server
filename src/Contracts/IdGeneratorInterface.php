<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

interface IdGeneratorInterface
{
    public function generateId(): string;
}
