<?php

namespace PhpMcp\Server\Defaults;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Basic PSR-16 file cache implementation.
 *
 * Stores cache entries serialized in a JSON file.
 * Uses file locking for basic concurrency control during writes.
 * Not recommended for high-concurrency environments.
 */
class FileCache implements CacheInterface
{
    /**
     * @param  string  $cacheFile  Absolute path to the cache file.
     *                             The directory will be created if it doesn't exist.
     * @param  int  $filePermission  Optional file mode (octal) for the cache file (default: 0664).
     * @param  int  $dirPermission  Optional directory mode (octal) for the cache directory (default: 0775).
     */
    public function __construct(
        private readonly string $cacheFile,
        private readonly int $filePermission = 0664,
        private readonly int $dirPermission = 0775
    ) {
        $this->ensureDirectoryExists(dirname($this->cacheFile));
    }

    // ---------------------------------------------------------------------
    // PSR-16 Methods
    // ---------------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->readCacheFile();
        $key = $this->sanitizeKey($key);

        if (! isset($data[$key])) {
            return $default;
        }

        if ($this->isExpired($data[$key]['expiry'])) {
            $this->delete($key); // Clean up expired entry

            return $default;
        }

        return $data[$key]['value'] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $data = $this->readCacheFile();
        $key = $this->sanitizeKey($key);

        $data[$key] = [
            'value' => $value,
            'expiry' => $this->calculateExpiry($ttl),
        ];

        return $this->writeCacheFile($data);
    }

    public function delete(string $key): bool
    {
        $data = $this->readCacheFile();
        $key = $this->sanitizeKey($key);

        if (isset($data[$key])) {
            unset($data[$key]);

            return $this->writeCacheFile($data);
        }

        return true; // Key didn't exist, considered successful delete
    }

    public function clear(): bool
    {
        // Write an empty array to the file
        return $this->writeCacheFile([]);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);

        $data = $this->readCacheFile();
        $results = [];
        $needsWrite = false;

        foreach ($keys as $key) {
            $sanitizedKey = $this->sanitizeKey($key);
            if (! isset($data[$sanitizedKey])) {
                $results[$key] = $default;

                continue;
            }

            if ($this->isExpired($data[$sanitizedKey]['expiry'])) {
                unset($data[$sanitizedKey]); // Clean up expired entry
                $needsWrite = true;
                $results[$key] = $default;

                continue;
            }

            $results[$key] = $data[$sanitizedKey]['value'] ?? $default;
        }

        if ($needsWrite) {
            $this->writeCacheFile($data);
        }

        return $results;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $values = $this->iterableToArray($values);
        $this->validateKeys(array_keys($values));

        $data = $this->readCacheFile();
        $expiry = $this->calculateExpiry($ttl);

        foreach ($values as $key => $value) {
            $sanitizedKey = $this->sanitizeKey((string) $key);
            $data[$sanitizedKey] = [
                'value' => $value,
                'expiry' => $expiry,
            ];
        }

        return $this->writeCacheFile($data);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);

        $data = $this->readCacheFile();
        $deleted = false;

        foreach ($keys as $key) {
            $sanitizedKey = $this->sanitizeKey($key);
            if (isset($data[$sanitizedKey])) {
                unset($data[$sanitizedKey]);
                $deleted = true;
            }
        }

        if ($deleted) {
            return $this->writeCacheFile($data);
        }

        return true; // No keys existed or no changes made
    }

    public function has(string $key): bool
    {
        $data = $this->readCacheFile();
        $key = $this->sanitizeKey($key);

        if (! isset($data[$key])) {
            return false;
        }

        if ($this->isExpired($data[$key]['expiry'])) {
            $this->delete($key); // Clean up expired

            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Internal Methods
    // ---------------------------------------------------------------------

    private function readCacheFile(): array
    {
        if (! file_exists($this->cacheFile) || filesize($this->cacheFile) === 0) {
            return [];
        }

        $handle = @fopen($this->cacheFile, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return [];
            }
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);

            if ($content === false || $content === '') {
                return [];
            }

            $data = unserialize($content);
            if ($data === false) {
                return [];
            }

            return $data;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function writeCacheFile(array $data): bool
    {
        $jsonData = serialize($data);

        if ($jsonData === false) {
            return false;
        }

        $handle = @fopen($this->cacheFile, 'cb');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }
            if (! ftruncate($handle, 0)) {
                return false;
            }
            if (fwrite($handle, $jsonData) === false) {
                return false;
            }
            fflush($handle);
            flock($handle, LOCK_UN);
            @chmod($this->cacheFile, $this->filePermission);

            return true;
        } catch (Throwable $e) {
            flock($handle, LOCK_UN); // Ensure lock release on error

            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            if (! @mkdir($directory, $this->dirPermission, true)) {
                throw new InvalidArgumentException("Cache directory does not exist and could not be created: {$directory}");
            }
            @chmod($directory, $this->dirPermission);
        }
    }

    private function calculateExpiry(DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        $now = time();
        if (is_int($ttl)) {
            return $ttl <= 0 ? $now - 1 : $now + $ttl;
        }
        if ($ttl instanceof DateInterval) {
            try {
                return (new DateTimeImmutable())->add($ttl)->getTimestamp();
            } catch (Throwable $e) {
                return null;
            }
        }
        throw new InvalidArgumentException('Invalid TTL type provided. Must be null, int, or DateInterval.');
    }

    private function isExpired(?int $expiry): bool
    {
        return $expiry !== null && time() >= $expiry;
    }

    private function sanitizeKey(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty.');
        }

        // PSR-16 validation (optional stricter check)
        // if (preg_match('/[{}()\/@:]/', $key)) {
        //     throw new InvalidArgumentException("Cache key \"{$key}\" contains reserved characters.");
        // }
        return $key;
    }

    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string, got ' . gettype($key));
            }
            $this->sanitizeKey($key);
        }
    }

    private function iterableToArray(iterable $iterable): array
    {
        if (is_array($iterable)) {
            return $iterable;
        }

        return iterator_to_array($iterable);
    }
}
