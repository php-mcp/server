<?php

namespace PhpMcp\Server\Tests\Unit\Utils;

use Mockery;
use PhpMcp\Server\Utils\SchemaValidator;
use Psr\Log\LoggerInterface;
use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Attributes\Schema\ArrayItems;
use PhpMcp\Server\Attributes\Schema\Format;
use PhpMcp\Server\Attributes\Schema\Property;

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
            'optionalValue' => ['type' => 'string'], // Not required
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
        'optionalValue' => 'present',
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
        'items' => ['type' => 'integer'],
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
        ->and($errors[0]['keyword'])->toBe('required');
});

test('handles empty schema (allows anything)', function () {
    $schema = []; // Empty schema object/array implies no constraints
    $data = ['anything' => [1, 2], 'goes' => true];

    $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

    expect($errors)->not->toBeEmpty()
        ->and($errors[0]['keyword'])->toBe('internal')
        ->and($errors[0]['message'])->toContain('Invalid schema');
});

test('validates schema with string format constraints from Schema attribute', function () {
    $emailSchema = (new Schema(format: 'email'))->toArray();

    // Valid email
    $validErrors = $this->validator->validateAgainstJsonSchema('user@example.com', $emailSchema);
    expect($validErrors)->toBeEmpty();

    // Invalid email
    $invalidErrors = $this->validator->validateAgainstJsonSchema('not-an-email', $emailSchema);
    expect($invalidErrors)->not->toBeEmpty()
        ->and($invalidErrors[0]['keyword'])->toBe('format')
        ->and($invalidErrors[0]['message'])->toContain('email');
});

test('validates schema with string length constraints from Schema attribute', function () {
    $passwordSchema = (new Schema(minLength: 8, pattern: '^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$'))->toArray();

    // Valid password (meets length and pattern)
    $validErrors = $this->validator->validateAgainstJsonSchema('Password123', $passwordSchema);
    expect($validErrors)->toBeEmpty();

    // Invalid - too short
    $shortErrors = $this->validator->validateAgainstJsonSchema('Pass1', $passwordSchema);
    expect($shortErrors)->not->toBeEmpty()
        ->and($shortErrors[0]['keyword'])->toBe('minLength');

    // Invalid - no digit
    $noDigitErrors = $this->validator->validateAgainstJsonSchema('PasswordXYZ', $passwordSchema);
    expect($noDigitErrors)->not->toBeEmpty()
        ->and($noDigitErrors[0]['keyword'])->toBe('pattern');
});

test('validates schema with numeric constraints from Schema attribute', function () {
    $ageSchema = (new Schema(minimum: 18, maximum: 120))->toArray();

    // Valid age
    $validErrors = $this->validator->validateAgainstJsonSchema(25, $ageSchema);
    expect($validErrors)->toBeEmpty();

    // Invalid - too low
    $tooLowErrors = $this->validator->validateAgainstJsonSchema(15, $ageSchema);
    expect($tooLowErrors)->not->toBeEmpty()
        ->and($tooLowErrors[0]['keyword'])->toBe('minimum');

    // Invalid - too high
    $tooHighErrors = $this->validator->validateAgainstJsonSchema(150, $ageSchema);
    expect($tooHighErrors)->not->toBeEmpty()
        ->and($tooHighErrors[0]['keyword'])->toBe('maximum');
});

test('validates schema with array constraints from Schema attribute', function () {
    $tagsSchema = (new Schema(uniqueItems: true, minItems: 2))->toArray();

    // Valid tags array
    $validErrors = $this->validator->validateAgainstJsonSchema(['php', 'javascript', 'python'], $tagsSchema);
    expect($validErrors)->toBeEmpty();

    // Invalid - duplicate items
    $duplicateErrors = $this->validator->validateAgainstJsonSchema(['php', 'php', 'javascript'], $tagsSchema);
    expect($duplicateErrors)->not->toBeEmpty()
        ->and($duplicateErrors[0]['keyword'])->toBe('uniqueItems');

    // Invalid - too few items
    $tooFewErrors = $this->validator->validateAgainstJsonSchema(['php'], $tagsSchema);
    expect($tooFewErrors)->not->toBeEmpty()
        ->and($tooFewErrors[0]['keyword'])->toBe('minItems');
});

test('validates schema with object constraints from Schema attribute', function () {
    $userSchema = (new Schema(
        properties: [
            'name' => ['type' => 'string', 'minLength' => 2],
            'email' => ['type' => 'string', 'format' => 'email'],
            'age' => ['type' => 'integer', 'minimum' => 18]
        ],
        required: ['name', 'email']
    ))->toArray();

    // Valid user object
    $validUser = [
        'name' => 'John',
        'email' => 'john@example.com',
        'age' => 25
    ];
    $validErrors = $this->validator->validateAgainstJsonSchema($validUser, $userSchema);
    expect($validErrors)->toBeEmpty();

    // Invalid - missing required email
    $missingEmailUser = [
        'name' => 'John',
        'age' => 25
    ];
    $missingErrors = $this->validator->validateAgainstJsonSchema($missingEmailUser, $userSchema);
    expect($missingErrors)->not->toBeEmpty()
        ->and($missingErrors[0]['keyword'])->toBe('required');

    // Invalid - name too short
    $shortNameUser = [
        'name' => 'J',
        'email' => 'john@example.com',
        'age' => 25
    ];
    $nameErrors = $this->validator->validateAgainstJsonSchema($shortNameUser, $userSchema);
    expect($nameErrors)->not->toBeEmpty()
        ->and($nameErrors[0]['keyword'])->toBe('minLength');

    // Invalid - age too low
    $youngUser = [
        'name' => 'John',
        'email' => 'john@example.com',
        'age' => 15
    ];
    $ageErrors = $this->validator->validateAgainstJsonSchema($youngUser, $userSchema);
    expect($ageErrors)->not->toBeEmpty()
        ->and($ageErrors[0]['keyword'])->toBe('minimum');
});

test('validates schema with nested constraints from Schema attribute', function () {
    $orderSchema = (new Schema(
        properties: [
            'customer' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => '^CUS-[0-9]{6}$'],
                    'name' => ['type' => 'string', 'minLength' => 2]
                ],
            ],
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'pattern' => '^PRD-[0-9]{4}$'],
                        'quantity' => ['type' => 'integer', 'minimum' => 1]
                    ],
                    'required' => ['product_id', 'quantity']
                ]
            ]
        ],
        required: ['customer', 'items']
    ))->toArray();

    // Valid order
    $validOrder = [
        'customer' => [
            'id' => 'CUS-123456',
            'name' => 'John'
        ],
        'items' => [
            [
                'product_id' => 'PRD-1234',
                'quantity' => 2
            ]
        ]
    ];
    $validErrors = $this->validator->validateAgainstJsonSchema($validOrder, $orderSchema);
    expect($validErrors)->toBeEmpty();

    // Invalid - bad customer ID format
    $badCustomerIdOrder = [
        'customer' => [
            'id' => 'CUST-123', // Wrong format
            'name' => 'John'
        ],
        'items' => [
            [
                'product_id' => 'PRD-1234',
                'quantity' => 2
            ]
        ]
    ];
    $customerIdErrors = $this->validator->validateAgainstJsonSchema($badCustomerIdOrder, $orderSchema);
    expect($customerIdErrors)->not->toBeEmpty()
        ->and($customerIdErrors[0]['keyword'])->toBe('pattern');

    // Invalid - empty items array
    $emptyItemsOrder = [
        'customer' => [
            'id' => 'CUS-123456',
            'name' => 'John'
        ],
        'items' => []
    ];
    $emptyItemsErrors = $this->validator->validateAgainstJsonSchema($emptyItemsOrder, $orderSchema);
    expect($emptyItemsErrors)->not->toBeEmpty()
        ->and($emptyItemsErrors[0]['keyword'])->toBe('minItems');

    // Invalid - missing required property in items
    $missingProductIdOrder = [
        'customer' => [
            'id' => 'CUS-123456',
            'name' => 'John'
        ],
        'items' => [
            [
                // Missing product_id
                'quantity' => 2
            ]
        ]
    ];
    $missingProductIdErrors = $this->validator->validateAgainstJsonSchema($missingProductIdOrder, $orderSchema);
    expect($missingProductIdErrors)->not->toBeEmpty()
        ->and($missingProductIdErrors[0]['keyword'])->toBe('required');
});
