<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use SessionHandlerInterface;

class ArraySessionHandler implements SessionHandlerInterface
{
    /**
     * @var array<string, array{ data: array, timestamp: int }>
     */
    protected array $store = [];

    public function __construct(public readonly int $ttl = 3600) {}

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string|false
    {
        $session = $this->store[$sessionId] ?? '';
        if ($session === '') {
            return false;
        }

        $currentTimestamp = time();

        if ($currentTimestamp - $session['timestamp'] > $this->ttl) {
            unset($this->store[$sessionId]);
            return false;
        }

        return $session['data'];
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->store[$sessionId] = [
            'data' => $data,
            'timestamp' => time(),
        ];

        return true;
    }

    public function destroy(string $sessionId): bool
    {
        if (isset($this->store[$sessionId])) {
            unset($this->store[$sessionId]);
        }

        return true;
    }

    public function gc(int $maxLifetime): int|false
    {
        $currentTimestamp = time();

        $deletedSessions = 0;
        foreach ($this->store as $sessionId => $session) {
            if ($currentTimestamp - $session['timestamp'] > $maxLifetime) {
                unset($this->store[$sessionId]);
                $deletedSessions++;
            }
        }

        return $deletedSessions;
    }
}
