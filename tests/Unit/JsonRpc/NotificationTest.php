<?php

namespace PhpMcp\Server\Tests\Unit\JsonRpc;

use PhpMcp\Server\Exception\ProtocolException;
use PhpMcp\Server\JsonRpc\Notification;

test('notification construction sets properties correctly', function () {
    $notification = new Notification('2.0', 'test.method', ['param1' => 'value1']);

    expect($notification->jsonrpc)->toBe('2.0');
    expect($notification->method)->toBe('test.method');
    expect($notification->params)->toBe(['param1' => 'value1']);
});

test('notification can be created without params', function () {
    $notification = new Notification('2.0', 'test.method');

    expect($notification->params)->toBe([]);
});

test('make static method creates notification with default jsonrpc version', function () {
    $notification = Notification::make('test.method', ['param1' => 'value1']);

    expect($notification->jsonrpc)->toBe('2.0');
    expect($notification->method)->toBe('test.method');
    expect($notification->params)->toBe(['param1' => 'value1']);
});

test('make static method handles empty params', function () {
    $notification = Notification::make('test.method');

    expect($notification->params)->toBe([]);
});

test('fromArray creates valid notification from complete data', function () {
    $data = [
        'jsonrpc' => '2.0',
        'method' => 'test.method',
        'params' => ['param1' => 'value1'],
    ];

    $notification = Notification::fromArray($data);

    expect($notification->jsonrpc)->toBe('2.0');
    expect($notification->method)->toBe('test.method');
    expect($notification->params)->toBe(['param1' => 'value1']);
});

test('fromArray handles missing params', function () {
    $data = [
        'jsonrpc' => '2.0',
        'method' => 'test.method',
    ];

    $notification = Notification::fromArray($data);

    expect($notification->params)->toBe([]);
});

test('fromArray throws ProtocolException for invalid jsonrpc version', function () {
    $data = ['jsonrpc' => '1.0', 'method' => 'test.method'];
    expect(fn () => Notification::fromArray($data))->toThrow(ProtocolException::class); // UPDATED
});

test('fromArray throws ProtocolException for missing jsonrpc', function () {
    $data = ['method' => 'test.method'];
    expect(fn () => Notification::fromArray($data))->toThrow(ProtocolException::class); // UPDATED
});

test('fromArray throws ProtocolException for missing method', function () {
    $data = ['jsonrpc' => '2.0'];
    expect(fn () => Notification::fromArray($data))->toThrow(ProtocolException::class); // UPDATED
});

test('fromArray throws ProtocolException for non-string method', function () {
    $data = ['jsonrpc' => '2.0', 'method' => 123];
    expect(fn () => Notification::fromArray($data))->toThrow(ProtocolException::class); // UPDATED
});

test('fromArray throws ProtocolException if params is not an array/object', function () {
    $data = ['jsonrpc' => '2.0', 'method' => 'test', 'params' => 'string'];
    expect(fn () => Notification::fromArray($data))->toThrow(ProtocolException::class);
});

test('toArray returns correct structure with params', function () {
    $notification = new Notification('2.0', 'test.method', ['param1' => 'value1']);

    $array = $notification->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'method' => 'test.method',
        'params' => ['param1' => 'value1'],
    ]);
});

test('toArray omits empty params', function () {
    $notification = new Notification('2.0', 'test.method');

    $array = $notification->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'method' => 'test.method',
    ]);
    expect($array)->not->toHaveKey('params');
});

test('notification can be json encoded', function () {
    $notification = new Notification('2.0', 'test.method', ['param1' => 'value1']);

    $json = json_encode($notification);

    expect($json)->toBe('{"jsonrpc":"2.0","method":"test.method","params":{"param1":"value1"}}');
});
