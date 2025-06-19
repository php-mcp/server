<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

class NonDiscoverableClass
{
    public function someMethod(): string
    {
        return "Just a regular method.";
    }
}

interface MyDiscoverableInterface
{
}

trait MyDiscoverableTrait
{
    public function traitMethod()
    {
    }
}

enum MyDiscoverableEnum
{
    case Alpha;
}
