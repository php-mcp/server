<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

interface SessionIdGeneratorInterface
{
    /**
     * Generate a unique session ID.
     *
     * @return string The generated session ID
     */
    public function generateId(): string;

    /**
     * Called when a session is initialized
     *
     * @param string $sessionId The session ID
     */
    public function onSessionInitialized(string $sessionId): void;
}
