<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

interface CompletionProviderInterface
{
    /**
     * Get completions for a given current value.
     *
     * @param  string  $currentValue  The current value to get completions for.
     * @param  SessionInterface  $session  The session to get completions for.
     * @return array  The completions.
     */
    public function getCompletions(string $currentValue, SessionInterface $session): array;
}
