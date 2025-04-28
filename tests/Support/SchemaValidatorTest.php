<?php

namespace PhpMcp\Server\Tests\Support;

use PhpMcp\Server\Support\SchemaValidator;
use Psr\Log\LoggerInterface;
use Mockery;

// --- Setup ---
beforeEach(function () {
    /** @var \Mockery\MockInterface&\Psr\Log\LoggerInterface */
    $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->validator = new SchemaValidator($this->loggerMock);
});

// --- Helper Data & Schemas ---
function getSimpleSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'The name'],
            'age' => ['type' => 'integer', 'minimum' => 0],
            'active' => ['type' => 'boolean'],
            'score' => ['type' => 'number'],
            'items' => ['type' => 'array', 'items' => ['type' => 'string']],
            'nullableValue' => ['type' => ['string', 'null']],
            'optionalValue' => ['type' => 'string'] // Not required
        ],
        'required' => ['name', 'age', 'active', 'score', 'items', 'nullableValue'],
        'additionalProperties' => false,
    ];
}

function getValidData(): array
{
    return [
        'name' => 'Tester',
        'age' => 30,
        'active' => true,
        'score' => 99.5,
        'items' => ['a', 'b'],
        'nullableValue' => null,
        'optionalValue' => 'present'
    ];
}

// --- Basic Validation Tests ---

test('valid data passes validation', function () {
    $schema = getSimpleSchema();
    $data = getValidData();

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

test('invalid type generates type error', function () {
    $schema = getSimpleSchema();
    $data = getValidData();
    $data['age'] = 'thirty'; // Invalid type

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['pointer'])->toBe('/age')
        ->and($errors[0]['keyword'])->toBe('type')
        ->and($errors[0]['message'])->toContain('Expected `integer`');
});

test('missing required property generates required error', function () {
    $schema = getSimpleSchema();
    $data = getValidData();
    unset($data['name']); // Missing required

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('required')
        ->and($errors[0]['message'])->toContain('Missing required properties: `name`');
});

test('additional property generates additionalProperties error', function () {
    $schema = getSimpleSchema();
    $data = getValidData();
    $data['extra'] = 'not allowed'; // Additional property

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['pointer'])->toBe('/') // Error reported at the object root
        ->and($errors[0]['keyword'])->toBe('additionalProperties')
        ->and($errors[0]['message'])->toContain('Additional object properties are not allowed: ["extra"]');
});

// --- Keyword Constraint Tests ---

test('enum constraint violation', function () {
    $schema = ['type' => 'string', 'enum' => ['A', 'B']];
    $data = 'C';

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('enum')
        ->and($errors[0]['message'])->toContain('must be one of the allowed values: "A", "B"');
});

test('minimum constraint violation', function () {
    $schema = ['type' => 'integer', 'minimum' => 10];
    $data = 5;

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('minimum')
        ->and($errors[0]['message'])->toContain('must be greater than or equal to 10');
});

test('maxLength constraint violation', function () {
    $schema = ['type' => 'string', 'maxLength' => 5];
    $data = 'toolong';

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('maxLength')
        ->and($errors[0]['message'])->toContain('Maximum string length is 5, found 7');
});

test('pattern constraint violation', function () {
    $schema = ['type' => 'string', 'pattern' => '^[a-z]+$'];
    $data = '123';

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('pattern')
        ->and($errors[0]['message'])->toContain('does not match the required pattern: `^[a-z]+$`');
});

test('minItems constraint violation', function () {
    $schema = ['type' => 'array', 'minItems' => 2];
    $data = ['one'];

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('minItems')
        ->and($errors[0]['message'])->toContain('Array should have at least 2 items, 1 found');
});

test('uniqueItems constraint violation', function () {
    $schema = ['type' => 'array', 'uniqueItems' => true];
    $data = ['a', 'b', 'a'];

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('uniqueItems')
        ->and($errors[0]['message'])->toContain('Array must have unique items');
});


// --- Nested Structures and Pointers ---
test('nested object validation error pointer', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'user' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ],
        ],
        'required' => ['user'],
    ];
    $data = ['user' => ['id' => 'abc']]; // Invalid nested type

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['pointer'])->toBe('/user/id');
});

test('array item validation error pointer', function () {
    $schema = [
        'type' => 'array',
        'items' => ['type' => 'integer']
    ];
    $data = [1, 2, 'three', 4]; // Invalid item type

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['pointer'])->toBe('/2'); // Pointer to the index of the invalid item
});

// --- Data Conversion Tests ---
test('validates data passed as stdClass object', function () {
    $schema = getSimpleSchema();
    $dataObj = json_decode(json_encode(getValidData())); // Convert to stdClass

    $errors = $this->validator->validateAgainstJsonSchema($dataObj, $schema);
    expect($errors)->toBeEmpty();
});

test('validates data with nested associative arrays correctly', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'nested' => [
                'type' => 'object',
                'properties' => ['key' => ['type' => 'string']],
                'required' => ['key'],
            ],
        ],
        'required' => ['nested'],
    ];
    $data = ['nested' => ['key' => 'value']]; // Nested assoc array

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toBeEmpty();
});

// --- Edge Cases ---
test('handles invalid schema structure gracefully', function () {
    $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 123]]]; // Invalid type value
    $data = ['name' => 'test'];

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['keyword'])->toBe('internal')
        ->and($errors[0]['message'])->toContain('Schema validation process failed');
});

test('handles empty data object against schema requiring properties', function () {
    $schema = getSimpleSchema(); // Requires name, age etc.
    $data = []; // Empty data

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

    expect($errors)->not->toBeEmpty()
        ->and($errors[0]['keyword'])->toBe('type');
});

test('handles empty schema (allows anything)', function () {
    $schema = []; // Empty schema object/array implies no constraints
    $data = ['anything' => [1, 2], 'goes' => true];

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

    expect($errors)->not->toBeEmpty()
        ->and($errors[0]['keyword'])->toBe('internal')
        ->and($errors[0]['message'])->toContain('Invalid schema');
});
