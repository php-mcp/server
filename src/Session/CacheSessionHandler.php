<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use Psr\SimpleCache\CacheInterface;
use SessionHandlerInterface;

class CacheSessionHandler implements SessionHandlerInterface
{
    public function __construct(public readonly CacheInterface $cache, public readonly int $ttl = 3600)
    {
    }

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
        return $this->cache->get($sessionId, false);
    }

    public function write(string $sessionId, string $data): bool
    {
        return $this->cache->set($sessionId, $data, $this->ttl);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->cache->delete($sessionId);
    }

    public function gc(int $maxLifetime): int|false
    {
        return 0;
    }
}
