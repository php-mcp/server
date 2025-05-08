<?php

namespace PhpMcp\Server\Tests\Unit\JsonRpc;

use InvalidArgumentException;
// Use base exception for factory methods maybe
use PhpMcp\Server\Exception\ProtocolException; // Use this for fromArray errors
use PhpMcp\Server\JsonRpc\Error;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Result; // Keep for testing ::success factory
use PhpMcp\Server\JsonRpc\Results\EmptyResult; // Needed for type hints in factories

// --- Construction and Factory Tests (Mostly Unchanged) ---

test('response construction sets all properties for success response', function () {
    $resultObject = new EmptyResult(); // Use Result object for constructor test consistency
    $response = new Response('2.0', 1, $resultObject, null);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeInstanceOf(EmptyResult::class); // Constructor stores what's passed
    expect($response->error)->toBeNull();
});

test('response construction sets all properties for error response', function () {
    $error = new Error(100, 'Test error');
    $response = new Response('2.0', 1, null, $error); // Pass null ID if applicable

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
});

test('response construction allows null ID for error response', function () {
    $error = new Error(100, 'Test error');
    $response = new Response('2.0', null, null, $error); // Null ID allowed with error

    expect($response->id)->toBeNull();
    expect($response->error)->toBe($error);
    expect($response->result)->toBeNull();
});

test('response constructor throws exception if ID present but no result/error', function () {
    expect(fn () => new Response('2.0', 1, null, null))
        ->toThrow(InvalidArgumentException::class, 'must have either result or error');
});

test('response constructor throws exception if ID null but no error', function () {
    expect(fn () => new Response('2.0', null, null, null))
        ->toThrow(InvalidArgumentException::class, 'must have an error object');
});

test('response constructor throws exception if ID null and result present', function () {
    expect(fn () => new Response('2.0', null, ['data'], null))
        ->toThrow(InvalidArgumentException::class, 'response with null ID must have an error object');
});

test('response throws exception if both result and error are provided with ID', function () {
    $result = new EmptyResult();
    $error = new Error(100, 'Test error');
    expect(fn () => new Response('2.0', 1, $result, $error))->toThrow(InvalidArgumentException::class);
});

test('success static method creates success response', function () {
    $result = new EmptyResult();
    $response = Response::success($result, 1); // Factory still takes Result object

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeInstanceOf(EmptyResult::class); // Stores the Result object
    expect($response->error)->toBeNull();
});

test('error static method creates error response', function () {
    $error = new Error(100, 'Test error');
    $response = Response::error($error, 1); // With ID

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
});

test('error static method creates error response with null ID', function () {
    $error = new Error(100, 'Parse error');
    $response = Response::error($error, null); // Null ID

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBeNull();
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
});

// --- Status Check Tests (Unchanged) ---

test('isSuccess returns true for success response', function () {
    $result = new EmptyResult();
    $response = Response::success($result, 1); // Use factory
    expect($response->isSuccess())->toBeTrue();
});

test('isSuccess returns false for error response', function () {
    $error = new Error(100, 'Test error');
    $response = Response::error($error, 1); // Use factory
    expect($response->isSuccess())->toBeFalse();
});

test('isError returns true for error response', function () {
    $error = new Error(100, 'Test error');
    $response = Response::error($error, 1);
    expect($response->isError())->toBeTrue();
});

test('isError returns false for success response', function () {
    $result = new EmptyResult();
    $response = Response::success($result, 1);
    expect($response->isError())->toBeFalse();
});

// --- fromArray Tests (Updated) ---

test('fromArray creates valid success response with RAW result data', function () {
    $rawResultData = ['key' => 'value', 'items' => [1, 2]]; // Example raw result
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => $rawResultData, // Use raw data here
    ];

    $response = Response::fromArray($data);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    // *** Assert the RAW result data is stored ***
    expect($response->result)->toEqual($rawResultData);
    expect($response->result)->not->toBeInstanceOf(Result::class); // It shouldn't be a Result object yet
    expect($response->error)->toBeNull();
    expect($response->isSuccess())->toBeTrue();
});

test('fromArray creates valid error response with ID', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => ['code' => 100, 'message' => 'Test error'],
    ];

    $response = Response::fromArray($data);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBe(1);
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
    expect($response->error->code)->toBe(100);
    expect($response->error->message)->toBe('Test error');
    expect($response->isError())->toBeTrue();
});

test('fromArray creates valid error response with null ID', function () {
    $data = [
        'jsonrpc' => '2.0',
        'id' => null, // Explicit null ID
        'error' => ['code' => -32700, 'message' => 'Parse error'],
    ];

    $response = Response::fromArray($data);

    expect($response->jsonrpc)->toBe('2.0');
    expect($response->id)->toBeNull();
    expect($response->result)->toBeNull();
    expect($response->error)->toBeInstanceOf(Error::class);
    expect($response->error->code)->toBe(-32700);
    expect($response->error->message)->toBe('Parse error');
    expect($response->isError())->toBeTrue();
});

test('fromArray throws exception for invalid jsonrpc version', function () {
    $data = ['jsonrpc' => '1.0', 'id' => 1, 'result' => []];
    expect(fn () => Response::fromArray($data))->toThrow(ProtocolException::class);
});

test('fromArray throws exception for response with ID but missing result/error', function () {
    $data = ['jsonrpc' => '2.0', 'id' => 1];
    expect(fn () => Response::fromArray($data))->toThrow(ProtocolException::class, 'must contain either "result" or "error"');
});

test('fromArray throws exception for response with null ID but missing error', function () {
    $data = ['jsonrpc' => '2.0', 'id' => null]; // Missing error
    expect(fn () => Response::fromArray($data))->toThrow(ProtocolException::class, 'must contain "error" when ID is null');
});

test('fromArray throws exception for response with null ID and result present', function () {
    $data = ['jsonrpc' => '2.0', 'id' => null, 'result' => 'abc', 'error' => ['code' => -32700, 'message' => 'e']]; // Has result with null ID
    // Need to adjust mock data to pass initial checks if both present
    // Let's test the case where only result is present with null ID
    $dataOnlyResult = ['jsonrpc' => '2.0', 'id' => null, 'result' => 'abc'];
    expect(fn () => Response::fromArray($dataOnlyResult))
        ->toThrow(ProtocolException::class, 'must contain "error" when ID is null'); // Constructor check catches this via wrapper
});

test('fromArray throws exception for invalid ID type', function () {
    $data = ['jsonrpc' => '2.0', 'id' => [], 'result' => 'ok'];
    expect(fn () => Response::fromArray($data))->toThrow(ProtocolException::class, 'Invalid "id" field type');
});

test('fromArray throws exception for non-object error', function () {
    $data = ['jsonrpc' => '2.0', 'id' => 1, 'error' => 'not an object'];
    expect(fn () => Response::fromArray($data))->toThrow(ProtocolException::class, 'Invalid "error" field');
});

test('fromArray throws exception for invalid error object structure', function () {
    $data = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code_missing' => -1]];
    expect(fn () => Response::fromArray($data))
        ->toThrow(ProtocolException::class, 'Invalid "error" object structure'); // Message includes details from Error::fromArray
});

// --- toArray / jsonSerialize Tests (Updated) ---

test('toArray returns correct structure for success response with raw result', function () {
    // Create response with raw data (as if from ::fromArray)
    $rawResult = ['some' => 'data'];
    $response = new Response('2.0', 1, $rawResult); // Direct construction with raw data

    $array = $response->toArray();

    // toArray should output the raw result directly
    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => $rawResult, // Expect raw data
    ]);
});

test('toArray returns correct structure when using success factory (with Result obj)', function () {
    // Create response using ::success factory
    $resultObject = new EmptyResult();
    $response = Response::success($resultObject, 1);

    $array = $response->toArray();

    // toArray should call toArray() on the Result object
    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [], // Expect result of EmptyResult::toArray()
    ]);
});

test('toArray returns correct structure for error response', function () {
    $error = new Error(100, 'Test error');
    $response = Response::error($error, 1); // Use factory

    $array = $response->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => ['code' => 100, 'message' => 'Test error'],
    ]);
});

test('toArray returns correct structure for error response with null ID', function () {
    $error = new Error(-32700, 'Parse error');
    $response = Response::error($error, null); // Use factory with null ID

    $array = $response->toArray();

    expect($array)->toBe([
        'jsonrpc' => '2.0',
        'id' => null, // ID should be null
        'error' => ['code' => -32700, 'message' => 'Parse error'],
    ]);
});

test('jsonSerialize returns same result as toArray', function () {
    $result = new EmptyResult();
    $response = Response::success($result, 1);

    $array = $response->toArray();
    $json = $response->jsonSerialize();

    expect($json)->toBe($array);
});
