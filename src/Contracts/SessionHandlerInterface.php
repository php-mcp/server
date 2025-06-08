<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

interface SessionHandlerInterface
{
    /**
     * Read session data
     *
     * Returns an encoded string of the read data.
     * If nothing was read, it must return false.
     * @param string $id The session id to read data for.
     */
    public function read(string $id): string|false;

    /**
     * Write session data
     * @param string $id The session id.
     * @param string $data The encoded session data.
     */
    public function write(string $id, string $data): bool;

    /**
     * Destroy a session
     * @param string $id The session ID being destroyed.
     * The return value (usually TRUE on success, FALSE on failure).
     */
    public function destroy(string $id): bool;

    /**
     * Cleanup old sessions
     * Sessions that have not updated for
     * the last maxlifetime seconds will be removed.
     */
    public function gc(int $maxLifetime): array;
}
