<?php

declare(strict_types=1);

namespace PhpMcp\Server\Defaults;

use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;

class ListCompletionProvider implements CompletionProviderInterface
{
    public function __construct(private array $values) {}

    public function getCompletions(string $currentValue, SessionInterface $session): array
    {
        if (empty($currentValue)) {
            return $this->values;
        }

        return array_values(array_filter(
            $this->values,
            fn(string $value) => str_starts_with($value, $currentValue)
        ));
    }
}
