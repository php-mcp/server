<?php

namespace PhpMcp\Server\Tests\Fixtures\General;

use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;

class CompletionProviderFixture implements CompletionProviderInterface
{
    public static array $completions = ['alpha', 'beta', 'gamma'];
    public static string $lastCurrentValue = '';
    public static ?SessionInterface $lastSession = null;

    public function getCompletions(string $currentValue, SessionInterface $session): array
    {
        self::$lastCurrentValue = $currentValue;
        self::$lastSession = $session;

        return array_filter(self::$completions, fn ($item) => str_starts_with($item, $currentValue));
    }
}
