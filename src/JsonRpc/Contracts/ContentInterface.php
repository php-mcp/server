<?php

declare(strict_types=1);

namespace PhpMcp\Server\JsonRpc\Contracts;

use JsonSerializable;

interface ContentInterface extends JsonSerializable
{
    public function toArray(): array;
}
