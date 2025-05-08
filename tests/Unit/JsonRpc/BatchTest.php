<?php

namespace PhpMcp\Server\Tests\Unit\JsonRpc;

use PhpMcp\Server\Exception\ProtocolException;
use PhpMcp\Server\JsonRpc\Batch;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;

test('batch construction initializes empty requests array', function () {
    $batch = new Batch();

    expect($batch->getRequests())->toBeArray();
    expect($batch->getRequests())->toBeEmpty();
    expect($batch->count())->toBe(0);
});

test('batch construction with requests array', function () {
    $request = new Request('2.0', 1, 'test.method');
    $notification = new Notification('2.0', 'test.notification');

    $batch = new Batch([$request, $notification]);

    expect($batch->getRequests())->toHaveCount(2);
    expect($batch->getRequests()[0])->toBeInstanceOf(Request::class);
    expect($batch->getRequests()[1])->toBeInstanceOf(Notification::class);
    expect($batch->count())->toBe(2);
});

test('addRequest adds a request to the batch', function () {
    $batch = new Batch();
    $request = new Request('2.0', 1, 'test.method');

    $batch->addRequest($request);

    expect($batch->getRequests())->toHaveCount(1);
    expect($batch->getRequests()[0])->toBeInstanceOf(Request::class);
});

test('addRequest is chainable', function () {
    $batch = new Batch();
    $request = new Request('2.0', 1, 'test.method');
    $notification = new Notification('2.0', 'test.notification');

    $result = $batch->addRequest($request)->addRequest($notification);

    expect($result)->toBe($batch);
    expect($batch->getRequests())->toHaveCount(2);
});

test('getRequestsWithIds returns only requests with IDs', function () {
    $request1 = new Request('2.0', 1, 'test.method1');
    $request2 = new Request('2.0', 2, 'test.method2');
    $notification = new Notification('2.0', 'test.notification');

    $batch = new Batch([$request1, $notification, $request2]);

    $requestsWithIds = $batch->getRequestsWithIds();
    expect($requestsWithIds)->toHaveCount(2);
    expect($requestsWithIds[0])->toBeInstanceOf(Request::class);
    expect($requestsWithIds[0]->id)->toBe(1);
    expect($requestsWithIds[2])->toBeInstanceOf(Request::class);
    expect($requestsWithIds[2]->id)->toBe(2);
});

test('getNotifications returns only notifications', function () {
    $request = new Request('2.0', 1, 'test.method');
    $notification1 = new Notification('2.0', 'test.notification1');
    $notification2 = new Notification('2.0', 'test.notification2');

    $batch = new Batch([$request, $notification1, $notification2]);

    $notifications = $batch->getNotifications();
    expect($notifications)->toHaveCount(2);
    expect($notifications[1])->toBeInstanceOf(Notification::class);
    expect($notifications[1]->method)->toBe('test.notification1');
    expect($notifications[2])->toBeInstanceOf(Notification::class);
    expect($notifications[2]->method)->toBe('test.notification2');
});

test('count returns correct number of requests', function () {
    $batch = new Batch();
    expect($batch->count())->toBe(0);

    $batch->addRequest(new Request('2.0', 1, 'test.method'));
    expect($batch->count())->toBe(1);

    $batch->addRequest(new Notification('2.0', 'test.notification'));
    expect($batch->count())->toBe(2);
});

test('fromArray creates batch from array of requests and notifications', function () {
    $data = [
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'test.method1',
            'params' => [],
        ],
        [
            'jsonrpc' => '2.0',
            'method' => 'test.notification',
            'params' => [],
        ],
        [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'test.method2',
            'params' => ['param1' => 'value1'],
        ],
    ];

    $batch = Batch::fromArray($data);

    expect($batch->count())->toBe(3);
    expect($batch->getRequests()[0])->toBeInstanceOf(Request::class);
    expect($batch->getRequests()[0]->id)->toBe(1);
    expect($batch->getRequests()[1])->toBeInstanceOf(Notification::class);
    expect($batch->getRequests()[1]->method)->toBe('test.notification');
    expect($batch->getRequests()[2])->toBeInstanceOf(Request::class);
    expect($batch->getRequests()[2]->id)->toBe(2);
    expect($batch->getRequests()[2]->params)->toBe(['param1' => 'value1']);
});

test('fromArray throws exception for empty array', function () {
    expect(fn () => Batch::fromArray([]))->toThrow(ProtocolException::class);
});

test('fromArray throws exception for non-array item', function () {
    $data = [
        [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'test.method',
        ],
        'not an array',
    ];

    expect(fn () => Batch::fromArray($data))->toThrow(ProtocolException::class);
});

test('toArray returns array of request representations', function () {
    $request = new Request('2.0', 1, 'test.method', ['param1' => 'value1']);
    $notification = new Notification('2.0', 'test.notification');

    $batch = new Batch([$request, $notification]);

    $array = $batch->toArray();

    expect($array)->toBeArray();
    expect($array)->toHaveCount(2);
    expect($array[0])->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'test.method',
        'params' => ['param1' => 'value1'],
    ]);
    expect($array[1])->toBe([
        'jsonrpc' => '2.0',
        'method' => 'test.notification',
    ]);
});
