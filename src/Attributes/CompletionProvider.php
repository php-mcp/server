<?php

declare(strict_types=1);

namespace PhpMcp\Server\Attributes;

use Attribute;
use PhpMcp\Server\Contracts\CompletionProviderInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class CompletionProvider
{
    /**
     * @param class-string<CompletionProviderInterface> $providerClass FQCN of the completion provider class.
     */
    public function __construct(public string $providerClass) {}
}
