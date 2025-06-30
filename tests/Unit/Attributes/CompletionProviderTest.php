<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Unit\Attributes;

use PhpMcp\Server\Attributes\CompletionProvider;
use PhpMcp\Server\Tests\Fixtures\General\CompletionProviderFixture;
use PhpMcp\Server\Defaults\ListCompletionProvider;
use PhpMcp\Server\Defaults\EnumCompletionProvider;

enum TestEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}

it('can be constructed with provider class', function () {
    $attribute = new CompletionProvider(provider: CompletionProviderFixture::class);

    expect($attribute->provider)->toBe(CompletionProviderFixture::class);
    expect($attribute->values)->toBeNull();
    expect($attribute->enum)->toBeNull();
});

it('can be constructed with provider instance', function () {
    $instance = new CompletionProviderFixture();
    $attribute = new CompletionProvider(provider: $instance);

    expect($attribute->provider)->toBe($instance);
    expect($attribute->values)->toBeNull();
    expect($attribute->enum)->toBeNull();
});

it('can be constructed with values array', function () {
    $values = ['draft', 'published', 'archived'];
    $attribute = new CompletionProvider(values: $values);

    expect($attribute->provider)->toBeNull();
    expect($attribute->values)->toBe($values);
    expect($attribute->enum)->toBeNull();
});

it('can be constructed with enum class', function () {
    $attribute = new CompletionProvider(enum: TestEnum::class);

    expect($attribute->provider)->toBeNull();
    expect($attribute->values)->toBeNull();
    expect($attribute->enum)->toBe(TestEnum::class);
});

it('throws exception when no parameters provided', function () {
    new CompletionProvider();
})->throws(\InvalidArgumentException::class, 'Only one of provider, values, or enum can be set');

it('throws exception when multiple parameters provided', function () {
    new CompletionProvider(
        provider: CompletionProviderFixture::class,
        values: ['test']
    );
})->throws(\InvalidArgumentException::class, 'Only one of provider, values, or enum can be set');

it('throws exception when all parameters provided', function () {
    new CompletionProvider(
        provider: CompletionProviderFixture::class,
        values: ['test'],
        enum: TestEnum::class
    );
})->throws(\InvalidArgumentException::class, 'Only one of provider, values, or enum can be set');
