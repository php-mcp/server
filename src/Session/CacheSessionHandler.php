<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Defaults\SystemClock;
use Psr\SimpleCache\CacheInterface;
use Psr\Clock\ClockInterface;

class CacheSessionHandler implements SessionHandlerInterface
{
    private const SESSION_INDEX_KEY = 'mcp_session_index';
    private array $sessionIndex = [];
    private ClockInterface $clock;

    public function __construct(
        public readonly CacheInterface $cache,
        public readonly int $ttl = 3600,
        ?ClockInterface $clock = null
    ) {
        $this->sessionIndex = $this->cache->get(self::SESSION_INDEX_KEY, []);
        $this->clock = $clock ?? new SystemClock();
    }

    public function read(string $sessionId): string|false
    {
        $session = $this->cache->get($sessionId, false);
        if ($session === false) {
            if (isset($this->sessionIndex[$sessionId])) {
                unset($this->sessionIndex[$sessionId]);
                $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);
            }
            return false;
        }

        if (!isset($this->sessionIndex[$sessionId])) {
            $this->sessionIndex[$sessionId] = $this->clock->now()->getTimestamp();
            $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);
            return $session;
        }

        if ($this->clock->now()->getTimestamp() - $this->sessionIndex[$sessionId] > $this->ttl) {
            $this->cache->delete($sessionId);
            return false;
        }

        return $session;
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->sessionIndex[$sessionId] = $this->clock->now()->getTimestamp();
        $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);
        return $this->cache->set($sessionId, $data);
    }

    public function destroy(string $sessionId): bool
    {
        unset($this->sessionIndex[$sessionId]);
        $this->cache->set(self::SESSION_INDEX_KEY, $this->sessionIndex);
        return $this->cache->delete($sessionId);
    }

    public function gc(int $maxLifetime): array
    {
        $currentTime = $this->clock->now()->getTimestamp();
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
