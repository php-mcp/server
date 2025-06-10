<?php

namespace PhpMcp\Server\Tests\Unit\JsonRpc;

use PhpMcp\Server\JsonRpc\Result;
use PhpMcp\Schema\Result\EmptyResult;

test('Result class can be extended', function () {
    $result = new EmptyResult();

    expect($result)->toBeInstanceOf(Result::class);
});

test('Result implementation must define toArray method', function () {
    $result = new EmptyResult();

    expect($result->toArray())->toBe([]);
});

test('jsonSerialize calls toArray method', function () {
    $result = new EmptyResult();

    $serialized = $result->jsonSerialize();

    expect($serialized)->toBe([]);
});

test('Result can be json encoded directly', function () {
    $result = new EmptyResult();

    $json = json_encode($result);

    expect($json)->toBe('[]');
});

// Define a custom Result implementation for testing
class TestResult extends Result
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}

test('Custom Result implementation works correctly', function () {
    $data = ['key' => 'value', 'nested' => ['nested_key' => 'nested_value']];
    $result = new TestResult($data);

    expect($result->toArray())->toBe($data);
    expect($result->jsonSerialize())->toBe($data);
    expect(json_encode($result))->toBe('{"key":"value","nested":{"nested_key":"nested_value"}}');
});
