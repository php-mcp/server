<?php

declare(strict_types=1);

namespace PhpMcp\Server\Defaults;

use PhpMcp\Server\Contracts\SessionIdGeneratorInterface;

class DefaultUuidSessionIdGenerator implements SessionIdGeneratorInterface
{
    public function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function onSessionInitialized(string $sessionId): void
    {
        // no-op
    }
}
