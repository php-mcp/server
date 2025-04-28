<?php

namespace PhpMcp\Server\Tests\JsonRpc;

use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\Exceptions\McpException;

test('request construction sets all properties', function () {
    $request = new Request('2.0', 1, 'test.method', ['param1' => 'value1']);

    expect($request->jsonrpc)->toBe('2.0');
    expect($request->id)->toBe(1);
    expect($request->method)->toBe('test.method');
    expect($request->params)->toBe(['param1' => 'value1']);
});

test('request can be created with string id', function () {
    $request = new Request('2.0', 'abc123', 'test.method');

    expect($request->id)->toBe('abc123');
});

test('request can be created without params', function () {
    $request = new Request('2.0', 1, 'test.method');

    expect($request->params)->toBe([]);
});

test('fromArray creates valid request from complete data', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test.method',
        'params' => ['param1' => 'value1'],
    ];

    $request = Request::fromArray($data);

    expect($request->jsonrpc)->toBe('2.0');
    expect($request->id)->toBe(1);
    expect($request->method)->toBe('test.method');
    expect($request->params)->toBe(['param1' => 'value1']);
});

test('fromArray handles missing params', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test.method',
    ];

    $request = Request::fromArray($data);

    expect($request->params)->toBe([]);
});

test('fromArray throws exception for invalid jsonrpc version', function () {
    $data = [
        'jsonrpc' => '1.0',
        'id' => 1,
        'method' => 'test.method',
    ];

    expect(fn () => Request::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for missing jsonrpc', function () {
    $data = [
        'id' => 1,
        'method' => 'test.method',
    ];

    expect(fn () => Request::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for missing method', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
    ];

    expect(fn () => Request::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for non-string method', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 123,
    ];

    expect(fn () => Request::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for missing id', function () {
    $data = [
        'jsonrpc' => '2.0',
        'method' => 'test.method',
    ];

    expect(fn () => Request::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for non-array params', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test.method',
        'params' => 'invalid',
    ];

    expect(fn () => Request::fromArray($data))->toThrow(McpException::class);
});

test('toArray returns correct structure with params', function () {
    $request = new Request('2.0', 1, 'test.method', ['param1' => 'value1']);

    $array = $request->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test.method',
        'params' => ['param1' => 'value1'],
    ]);
});

test('toArray omits empty params', function () {
    $request = new Request('2.0', 1, 'test.method');

    $array = $request->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test.method',
    ]);
    expect($array)->not->toHaveKey('params');
});
