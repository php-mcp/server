<?php

namespace PhpMcp\Server\Tests\Unit\JsonRpc;

use PhpMcp\Server\JsonRpc\Error;

test('error construction sets properties correctly', function () {
    $error = new Error(100, 'Test error message', ['details' => 'error details']);

    expect($error->code)->toBe(100);
    expect($error->message)->toBe('Test error message');
    expect($error->data)->toBe(['details' => 'error details']);
});

test('error can be created without data', function () {
    $error = new Error(100, 'Test error message');

    expect($error->data)->toBeNull();
});

test('fromArray creates valid error from complete data', function () {
    $data = [
        'code' => 100,
        'message' => 'Test error message',
        'data' => ['details' => 'error details'],
    ];

    $error = Error::fromArray($data);

    expect($error->code)->toBe(100);
    expect($error->message)->toBe('Test error message');
    expect($error->data)->toBe(['details' => 'error details']);
});

test('fromArray handles missing data', function () {
    $data = [
        'code' => 100,
        'message' => 'Test error message',
    ];

    $error = Error::fromArray($data);

    expect($error->data)->toBeNull();
});

test('toArray returns correct structure with data', function () {
    $error = new Error(100, 'Test error message', ['details' => 'error details']);

    $array = $error->toArray();

    expect($array)->toBe([
        'code' => 100,
        'message' => 'Test error message',
        'data' => ['details' => 'error details'],
    ]);
});

test('toArray omits null data', function () {
    $error = new Error(100, 'Test error message');

    $array = $error->toArray();

    expect($array)->toBe([
        'code' => 100,
        'message' => 'Test error message',
    ]);
    expect($array)->not->toHaveKey('data');
});
