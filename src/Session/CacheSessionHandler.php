<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use PhpMcp\Server\Contracts\SessionHandlerInterface;
use Psr\SimpleCache\CacheInterface;

class CacheSessionHandler implements SessionHandlerInterface
{
    private const SESSION_INDEX_KEY = 'mcp_session_index';
    private array $sessionIndex = [];

    public function __construct(
        public readonly CacheInterface $cache,
        public readonly int $ttl = 3600
    ) {
        $this->sessionIndex = $this->cache->get(self::SESSION_INDEX_KEY, []);
    }

    public function read(string $sessionId): string|false
    {
        return $this->cache->get($sessionId, false);
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->sessionIndex[$sessionId] = time();
        $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);
        return $this->cache->set($sessionId, $data, $this->ttl);
    }

    public function destroy(string $sessionId): bool
    {
        unset($this->sessionIndex[$sessionId]);
        $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);
        return $this->cache->delete($sessionId);
    }

    public function gc(int $maxLifetime): array
    {
        $currentTime = time();
        $deletedSessions = [];

        foreach ($this->sessionIndex as $sessionId => $timestamp) {
            if ($currentTime - $timestamp > $maxLifetime) {
                $this->cache->delete($sessionId);
                unset($this->sessionIndex[$sessionId]);
                $deletedSessions[] = $sessionId;
            }
        }

        $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);

        return $deletedSessions;
    }
}
