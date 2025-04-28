<?php

namespace PhpMcp\Server\Tests\JsonRpc\Results;

use PhpMcp\Server\JsonRpc\Result;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;

test('EmptyResult extends the Result base class', function () {
    $result = new EmptyResult();

    expect($result)->toBeInstanceOf(Result::class);
});

test('EmptyResult constructor takes no parameters', function () {
    $result = new EmptyResult();

    expect($result)->toBeInstanceOf(EmptyResult::class);
});

test('toArray returns an empty array', function () {
    $result = new EmptyResult();

    expect($result->toArray())->toBe([]);
    expect($result->toArray())->toBeEmpty();
});

test('jsonSerialize returns an empty array', function () {
    $result = new EmptyResult();

    expect($result->jsonSerialize())->toBe([]);
    expect($result->jsonSerialize())->toBeEmpty();
});

test('json_encode produces an empty JSON object', function () {
    $result = new EmptyResult();

    $json = json_encode($result);

    expect($json)->toBe('[]');
});
