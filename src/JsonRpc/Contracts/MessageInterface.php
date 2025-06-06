<?php

declare(strict_types=1);

namespace PhpMcp\Server\JsonRpc\Contracts;

use JsonSerializable;

interface MessageInterface extends JsonSerializable
{
    public function getId(): string|int|null;

    public function toArray(): array;
}
