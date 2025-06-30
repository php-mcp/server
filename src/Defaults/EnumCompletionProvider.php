<?php

declare(strict_types=1);

namespace PhpMcp\Server\Defaults;

use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;

class EnumCompletionProvider implements CompletionProviderInterface
{
    private array $values;

    public function __construct(string $enumClass)
    {
        if (!enum_exists($enumClass)) {
            throw new \InvalidArgumentException("Class {$enumClass} is not an enum");
        }

        $this->values = array_map(
            fn($case) => isset($case->value) && is_string($case->value) ? $case->value : $case->name,
            $enumClass::cases()
        );
    }

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
