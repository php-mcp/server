<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Defaults\SystemClock;
use Psr\Clock\ClockInterface;

class ArraySessionHandler implements SessionHandlerInterface
{
    /**
     * @var array<string, array{ data: array, timestamp: int }>
     */
    protected array $store = [];

    private ClockInterface $clock;

    public function __construct(
        public readonly int $ttl = 3600,
        ?ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function read(string $sessionId): string|false
    {
        $session = $this->store[$sessionId] ?? '';
        if ($session === '') {
            return false;
        }

        $currentTimestamp = $this->clock->now()->getTimestamp();

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
            'timestamp' => $this->clock->now()->getTimestamp(),
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

    public function gc(int $maxLifetime): array
    {
        $currentTimestamp = $this->clock->now()->getTimestamp();
        $deletedSessions = [];

        foreach ($this->store as $sessionId => $session) {
            if ($currentTimestamp - $session['timestamp'] > $maxLifetime) {
                unset($this->store[$sessionId]);
                $deletedSessions[] = $sessionId;
            }
        }

        return $deletedSessions;
    }
}
