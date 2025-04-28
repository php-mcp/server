<?php

namespace PhpMcp\Server\Tests\JsonRpc;

use PhpMcp\Server\JsonRpc\Message;

test('message construction sets jsonrpc version', function () {
    $message = new Message('2.0');
    expect($message->jsonrpc)->toBe('2.0');
});

test('toArray returns correct structure', function () {
    $message = new Message('2.0');

    $array = $message->toArray();

    expect($array)->toBe(['jsonrpc' => '2.0']);
});

test('jsonSerialize returns same result as toArray', function () {
    $message = new Message('2.0');

    $array = $message->toArray();
    $json = $message->jsonSerialize();

    expect($json)->toBe($array);
});

test('message can be json encoded directly', function () {
    $message = new Message('2.0');

    $json = json_encode($message);

    expect($json)->toBe('{"jsonrpc":"2.0"}');
});
