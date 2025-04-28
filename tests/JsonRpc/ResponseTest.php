<?php

namespace PhpMcp\Server\Tests\JsonRpc;

use InvalidArgumentException;
use PhpMcp\Server\JsonRpc\Error;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\Exceptions\McpException;

test('response construction sets all properties for success response', function () {
    $result = new EmptyResult();
    $response = new Response('2.0', 1, $result, null);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeInstanceOf(EmptyResult::class);
    expect($response->error)->toBeNull();
});

test('response construction sets all properties for error response', function () {
    $error = new Error(100, 'Test error');
    $response = new Response('2.0', 1, null, $error);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
    expect($response->error->code)->toBe(100);
    expect($response->error->message)->toBe('Test error');
});

test('response throws exception if both result and error are provided', function () {
    $result = new EmptyResult();
    $error = new Error(100, 'Test error');

    expect(fn () => new Response('2.0', 1, $result, $error))->toThrow(InvalidArgumentException::class);
});

test('success static method creates success response', function () {
    $result = new EmptyResult();
    $response = Response::success($result, 1);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeInstanceOf(EmptyResult::class);
    expect($response->error)->toBeNull();
});

test('error static method creates error response', function () {
    $error = new Error(100, 'Test error');
    $response = Response::error($error, 1);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
});

test('isSuccess returns true for success response', function () {
    $result = new EmptyResult();
    $response = new Response('2.0', 1, $result);

    expect($response->isSuccess())->toBeTrue();
});

test('isSuccess returns false for error response', function () {
    $error = new Error(100, 'Test error');
    $response = new Response('2.0', 1, null, $error);

    expect($response->isSuccess())->toBeFalse();
});

test('isError returns true for error response', function () {
    $error = new Error(100, 'Test error');
    $response = new Response('2.0', 1, null, $error);

    expect($response->isError())->toBeTrue();
});

test('isError returns false for success response', function () {
    $result = new EmptyResult();
    $response = new Response('2.0', 1, $result);

    expect($response->isError())->toBeFalse();
});

test('fromArray creates valid success response', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => new EmptyResult(),
    ];

    $response = Response::fromArray($data);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->not->toBeNull();
    expect($response->error)->toBeNull();
});

test('fromArray creates valid error response', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            'code' => 100,
            'message' => 'Test error',
        ],
    ];

    $response = Response::fromArray($data);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
    expect($response->error->code)->toBe(100);
    expect($response->error->message)->toBe('Test error');
});

test('fromArray throws exception for invalid jsonrpc version', function () {
    $data = [
        'jsonrpc' => '1.0',
        'id' => 1,
        'result' => [],
    ];

    expect(fn () => Response::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for missing result and error', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
    ];

    expect(fn () => Response::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for missing id', function () {
    $data = [
        'jsonrpc' => '2.0',
        'result' => [],
    ];

    expect(fn () => Response::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for non-object error', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => 'not an object',
    ];

    expect(fn () => Response::fromArray($data))->toThrow(McpException::class);
});

test('fromArray throws exception for invalid error object', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            // Missing code and message
        ],
    ];

    expect(fn () => Response::fromArray($data))->toThrow(McpException::class);
});

test('toArray returns correct structure for success response', function () {
    $result = new EmptyResult();
    $response = new Response('2.0', 1, $result);

    $array = $response->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [],
    ]);
});

test('toArray returns correct structure for error response', function () {
    $error = new Error(100, 'Test error');
    $response = new Response('2.0', 1, null, $error);

    $array = $response->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            'code' => 100,
            'message' => 'Test error',
        ],
    ]);
});

test('jsonSerialize returns same result as toArray', function () {
    $result = new EmptyResult();
    $response = new Response('2.0', 1, $result);

    $array = $response->toArray();
    $json = $response->jsonSerialize();

    expect($json)->toBe($array);
});
