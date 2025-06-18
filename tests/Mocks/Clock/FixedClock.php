<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Mocks\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use DateInterval;

class FixedClock implements ClockInterface
{
    private DateTimeImmutable $currentTime;

    public function __construct(string|DateTimeImmutable $initialTime = 'now', ?DateTimeZone $timezone = null)
    {
        if ($initialTime instanceof DateTimeImmutable) {
            $this->currentTime = $initialTime;
        } else {
            $this->currentTime = new DateTimeImmutable($initialTime, $timezone);
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function setCurrentTime(string|DateTimeImmutable $newTime, ?DateTimeZone $timezone = null): void
    {
        if ($newTime instanceof DateTimeImmutable) {
            $this->currentTime = $newTime;
        } else {
            $this->currentTime = new DateTimeImmutable($newTime, $timezone);
        }
    }

    public function advance(DateInterval $interval): void
    {
        $this->currentTime = $this->currentTime->add($interval);
    }

    public function rewind(DateInterval $interval): void
    {
        $this->currentTime = $this->currentTime->sub($interval);
    }

    public function addSecond(): void
    {
        $this->advance(new DateInterval("PT1S"));
    }

    public function addSeconds(int $seconds): void
    {
        $this->advance(new DateInterval("PT{$seconds}S"));
    }

    public function addMinutes(int $minutes): void
    {
        $this->advance(new DateInterval("PT{$minutes}M"));
    }

    public function addHours(int $hours): void
    {
        $this->advance(new DateInterval("PT{$hours}H"));
    }

    public function subSeconds(int $seconds): void
    {
        $this->rewind(new DateInterval("PT{$seconds}S"));
    }

    public function subMinutes(int $minutes): void
    {
        $this->rewind(new DateInterval("PT{$minutes}M"));
    }
}
