<?php

declare(strict_types=1);

namespace PhpMcp\Server\Attributes;

use Attribute;
use PhpMcp\Server\Contracts\CompletionProviderInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class CompletionProvider
{
    /**
     * @param class-string<CompletionProviderInterface>|null $providerClass 
     * @param class-string<CompletionProviderInterface>|CompletionProviderInterface|null $provider If a class-string, it will be resolved from the container at the point of use.
     */
    public function __construct(
        public ?string $providerClass = null,
        public string|CompletionProviderInterface|null $provider = null,
        public ?array $values = null,
        public ?string $enum = null,
    ) {
        if (count(array_filter([$provider, $values, $enum])) !== 1) {
            throw new \InvalidArgumentException('Only one of provider, values, or enum can be set');
        }
    }
}
