<?php

namespace PhpMcp\Server\Defaults;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;

/**
 * Very basic PSR-16 array cache implementation (not for production).
 */
class ArrayCache implements CacheInterface
{
    private array $store = [];

    private array $expiries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        return $this->store[$key];
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->store[$key] = $value;
        $this->expiries[$key] = $this->calculateExpiry($ttl);

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key], $this->expiries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->expiries = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $expiry = $this->calculateExpiry($ttl);
        foreach ($values as $key => $value) {
            $this->store[$key] = $value;
            $this->expiries[$key] = $expiry;
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key], $this->expiries[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (! isset($this->store[$key])) {
            return false;
        }
        // Check expiry
        if (isset($this->expiries[$key]) && $this->expiries[$key] !== null && time() >= $this->expiries[$key]) {
            $this->delete($key);

            return false;
        }

        return true;
    }

    private function calculateExpiry(DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null; // No expiry
        }
        if (is_int($ttl)) {
            return time() + $ttl;
        }
        if ($ttl instanceof DateInterval) {
            return (new DateTime())->add($ttl)->getTimestamp();
        }

        // Invalid TTL type, treat as no expiry
        return null;
    }
}
