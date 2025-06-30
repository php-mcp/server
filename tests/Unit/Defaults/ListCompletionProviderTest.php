<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Unit\Defaults;

use PhpMcp\Server\Defaults\ListCompletionProvider;
use PhpMcp\Server\Contracts\SessionInterface;
use Mockery;

beforeEach(function () {
    $this->session = Mockery::mock(SessionInterface::class);
});

it('returns all values when current value is empty', function () {
    $values = ['apple', 'banana', 'cherry'];
    $provider = new ListCompletionProvider($values);

    $result = $provider->getCompletions('', $this->session);

    expect($result)->toBe($values);
});

it('filters values based on current value prefix', function () {
    $values = ['apple', 'apricot', 'banana', 'cherry'];
    $provider = new ListCompletionProvider($values);

    $result = $provider->getCompletions('ap', $this->session);

    expect($result)->toBe(['apple', 'apricot']);
});

it('returns empty array when no values match', function () {
    $values = ['apple', 'banana', 'cherry'];
    $provider = new ListCompletionProvider($values);

    $result = $provider->getCompletions('xyz', $this->session);

    expect($result)->toBe([]);
});

it('works with single character prefix', function () {
    $values = ['apple', 'banana', 'cherry'];
    $provider = new ListCompletionProvider($values);

    $result = $provider->getCompletions('a', $this->session);

    expect($result)->toBe(['apple']);
});

it('is case sensitive by default', function () {
    $values = ['Apple', 'apple', 'APPLE'];
    $provider = new ListCompletionProvider($values);

    $result = $provider->getCompletions('A', $this->session);

    expect($result)->toEqual(['Apple', 'APPLE']);
});

it('handles empty values array', function () {
    $provider = new ListCompletionProvider([]);

    $result = $provider->getCompletions('test', $this->session);

    expect($result)->toBe([]);
});

it('preserves array order', function () {
    $values = ['zebra', 'apple', 'banana'];
    $provider = new ListCompletionProvider($values);

    $result = $provider->getCompletions('', $this->session);

    expect($result)->toBe(['zebra', 'apple', 'banana']);
});
