<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Unit\Defaults;

use PhpMcp\Server\Defaults\EnumCompletionProvider;
use PhpMcp\Server\Contracts\SessionInterface;
use Mockery;

enum StringEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}

enum IntEnum: int
{
    case LOW = 1;
    case MEDIUM = 2;
    case HIGH = 3;
}

enum UnitEnum
{
    case ALPHA;
    case BETA;
    case GAMMA;
}

beforeEach(function () {
    $this->session = Mockery::mock(SessionInterface::class);
});

it('creates provider from string-backed enum', function () {
    $provider = new EnumCompletionProvider(StringEnum::class);

    $result = $provider->getCompletions('', $this->session);

    expect($result)->toBe(['draft', 'published', 'archived']);
});

it('creates provider from int-backed enum using names', function () {
    $provider = new EnumCompletionProvider(IntEnum::class);

    $result = $provider->getCompletions('', $this->session);

    expect($result)->toBe(['LOW', 'MEDIUM', 'HIGH']);
});

it('creates provider from unit enum using names', function () {
    $provider = new EnumCompletionProvider(UnitEnum::class);

    $result = $provider->getCompletions('', $this->session);

    expect($result)->toBe(['ALPHA', 'BETA', 'GAMMA']);
});

it('filters string enum values by prefix', function () {
    $provider = new EnumCompletionProvider(StringEnum::class);

    $result = $provider->getCompletions('ar', $this->session);

    expect($result)->toEqual(['archived']);
});

it('filters unit enum values by prefix', function () {
    $provider = new EnumCompletionProvider(UnitEnum::class);

    $result = $provider->getCompletions('A', $this->session);

    expect($result)->toBe(['ALPHA']);
});

it('returns empty array when no values match prefix', function () {
    $provider = new EnumCompletionProvider(StringEnum::class);

    $result = $provider->getCompletions('xyz', $this->session);

    expect($result)->toBe([]);
});

it('throws exception for non-enum class', function () {
    new EnumCompletionProvider(\stdClass::class);
})->throws(\InvalidArgumentException::class, 'Class stdClass is not an enum');

it('throws exception for non-existent class', function () {
    new EnumCompletionProvider('NonExistentClass');
})->throws(\InvalidArgumentException::class, 'Class NonExistentClass is not an enum');
