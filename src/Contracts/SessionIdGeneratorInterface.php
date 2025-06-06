<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

interface SessionIdGeneratorInterface
{
    public function generateId(): string;

    /**
     * Called by the transport when a new session ID has been generated
     * and is about to be communicated to the client (e.g., in the
     * Mcp-Session-Id header of an initialize response).
     *
     * This allows for any side effects or logging related to session creation.
     *
     * @param string $sessionId The ID that was generated.
     */
    public function onSessionInitialized(string $sessionId): void;
}
