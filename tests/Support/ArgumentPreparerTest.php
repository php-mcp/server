<?php

namespace PhpMcp\Server\Tests\Support;

use PhpMcp\Server\Support\ArgumentPreparer;
use PhpMcp\Server\Tests\Mocks\SupportStubs\SchemaGeneratorTestStub;
use PhpMcp\Server\Exceptions\McpException;
use Psr\Log\LoggerInterface;
use Mockery;
use stdClass;
use ReflectionMethod;

// --- Setup ---
beforeEach(function () {
    /** @var \Mockery\MockInterface&\Psr\Log\LoggerInterface */
    $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->preparer = new ArgumentPreparer($this->loggerMock);
    $this->stubInstance = new SchemaGeneratorTestStub(); // Instance to reflect on
});

// --- Helper ---
function reflectMethod(string $methodName): ReflectionMethod
{
    return new ReflectionMethod(SchemaGeneratorTestStub::class, $methodName);
}

// --- Basic Argument Matching Tests ---

test('prepares empty array for method with no parameters', function () {
    $method = reflectMethod('noParams');
    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'noParams', []);
    expect($args)->toBe([]);
});

test('prepares arguments in correct order for simple required types', function () {
    $method = reflectMethod('simpleRequired');
    $input = [
        'p1' => 'hello',
        'p2' => 123,
        'p3' => true,
        'p4' => 4.56,
        'p5' => ['a', 'b'],
        'p6' => new stdClass(),
    ];
    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'simpleRequired', $input);
    expect($args)->toBe(['hello', 123, true, 4.56, ['a', 'b'], $input['p6']]);
});

test('uses default values when input not provided', function () {
    $method = reflectMethod('simpleOptionalDefaults');
    $input = ['p1' => 'provided']; // Only provide p1

    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'simpleOptionalDefaults', $input);
    expect($args)->toEqual(['provided', 123, true, 1.23, ['a', 'b'], null]);
});

test('handles nullable types without explicit default (passes null)', function () {
    $method = reflectMethod('nullableWithoutDefault');
    $input = []; // Provide no input

    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'nullableWithoutDefault', $input);
    // All params allow null and have no default, so they should receive null
    expect($args)->toEqual([null, null, null]);
});

test('handles nullable types with explicit null default', function () {
    $method = reflectMethod('nullableWithNullDefault');
    $input = []; // Provide no input

    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'nullableWithNullDefault', $input);
    // Both have explicit null defaults
    expect($args)->toEqual([null, null]);
});

// --- Type Casting Tests ---

test('casts valid input values to expected types', function (string $paramName, mixed $inputVal, mixed $expectedVal) {
    $method = reflectMethod('simpleRequired');
    $input = [
        'p1' => '', 'p2' => 0, 'p3' => false, 'p4' => 0.0, 'p5' => [], 'p6' => new stdClass(), // Base values
        $paramName => $inputVal // Use $paramName
    ];

    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'simpleRequired', $input);

    // Find the parameter by name to get its position
    $argPosition = -1;
    foreach ($method->getParameters() as $p) {
        if ($p->getName() === $paramName) {
            $argPosition = $p->getPosition();
            break;
        }
    }
    expect($argPosition)->not->toBe(-1, "Parameter {$paramName} not found in method."); // Assert parameter was found

    expect($args[$argPosition])->toEqual($expectedVal);

})->with([
    ['p1', 123, '123'],       // int to string
    ['p2', '456', 456],       // numeric string to int
    ['p2', '-10', -10],      // negative numeric string to int
    ['p2', 99.0, 99],       // float (whole) to int
    ['p3', 1, true],        // 1 to bool true
    ['p3', 'true', true],   // 'true' to bool true
    ['p3', 0, false],       // 0 to bool false
    ['p3', 'false', false], // 'false' to bool false
    ['p4', '7.89', 7.89],    // numeric string to float
    ['p4', 10, 10.0],       // int to float
    ['p5', [1,2], [1,2]],   // array passes through
    ['p6', (object)['a' => 1], (object)['a' => 1]], // object passes through
]);

test('throws McpException for invalid type casting', function (string $paramName, mixed $invalidInput, string $expectedType) {
    $method = reflectMethod('simpleRequired');
    $input = [
        'p1' => '', 'p2' => 0, 'p3' => false, 'p4' => 0.0, 'p5' => [], 'p6' => new stdClass(), // Base values
        $paramName => $invalidInput // Use $paramName
    ];

    $this->preparer->prepareMethodArguments($this->stubInstance, 'simpleRequired', $input);

})->throws(McpException::class)
 ->with([
    ['p2', 'abc', 'int'],       // non-numeric string to int
    ['p2', 12.3, 'int'],       // non-whole float to int
    ['p2', true, 'int'],       // bool to int
    ['p3', 'yes', 'bool'],     // 'yes' to bool
    ['p3', 2, 'bool'],       // 2 to bool
    ['p4', 'xyz', 'float'],    // non-numeric string to float
    ['p4', false, 'float'],    // bool to float
    ['p5', 'not_array', 'array'], // string to array
    ['p5', 123, 'array'],      // int to array
 ]);

test('throws McpException when required argument is missing', function () {
    $method = reflectMethod('simpleRequired');
    $input = ['p1' => 'hello']; // Missing p2, p3, etc.

    // Expect logger to be called because this is an invariant violation
    $this->loggerMock->shouldReceive('error')->once()->with(Mockery::pattern('/Invariant violation: Missing required argument `p2`/'), Mockery::any());

    $this->preparer->prepareMethodArguments($this->stubInstance, 'simpleRequired', $input);

})->throws(McpException::class, 'Missing required argument `p2`'); // Throws on the first missing one

// --- Edge Cases ---

test('handles untyped parameter (passes value through)', function () {
    $method = reflectMethod('docBlockOnly');
    $input = ['p1' => 'from_doc', 'p2' => 12345]; // p2 has no type hint

    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'docBlockOnly', $input);
    expect($args)->toEqual(['from_doc', 12345]);
});

// --- Enum Casting Tests (Requires PHP 8.1+) ---

test('casts valid input values to backed enums', function (string $paramName, mixed $inputVal, mixed $expectedEnumInstance) {
    $method = reflectMethod('enumTypes'); // Method with enum parameters
    $input = [
        // Provide valid base values for other required params (p1, p2, p3)
        'p1' => 'A',
        'p2' => 1,
        'p3' => 'Yes', // Assuming unit enums aren't handled by casting yet
        // Override the param being tested
        $paramName => $inputVal,
    ];

    $args = $this->preparer->prepareMethodArguments($this->stubInstance, 'enumTypes', $input);

    $argPosition = -1;
    foreach ($method->getParameters() as $p) {
        if ($p->getName() === $paramName) {
            $argPosition = $p->getPosition();
            break;
        }
    }
    expect($argPosition)->not->toBe(-1);

    expect($args[$argPosition])->toEqual($expectedEnumInstance); // Use toEqual for enums

})->with([
    ['p1', 'A', \PhpMcp\Server\Tests\Mocks\SupportStubs\BackedStringEnum::OptionA],
    ['p1', 'B', \PhpMcp\Server\Tests\Mocks\SupportStubs\BackedStringEnum::OptionB],
    ['p2', 1, \PhpMcp\Server\Tests\Mocks\SupportStubs\BackedIntEnum::First],
    ['p2', 2, \PhpMcp\Server\Tests\Mocks\SupportStubs\BackedIntEnum::Second],
    // p4 is nullable enum - test passing valid value
    ['p4', 'A', \PhpMcp\Server\Tests\Mocks\SupportStubs\BackedStringEnum::OptionA],
    // p5 is optional with default - test passing valid value
    ['p5', 2, \PhpMcp\Server\Tests\Mocks\SupportStubs\BackedIntEnum::Second],
]);

test('throws McpException for invalid enum values', function (string $paramName, mixed $invalidValue) {
    $method = reflectMethod('enumTypes');
    $input = [
        'p1' => 'A', 'p2' => 1, 'p3' => 'Yes', // Valid base values
        $paramName => $invalidValue, // Override with invalid value
    ];

    $this->preparer->prepareMethodArguments($this->stubInstance, 'enumTypes', $input);

})->throws(McpException::class) // Expect the wrapped exception
  ->with([
    ['p1', 'C'], // Invalid string for BackedStringEnum
    ['p2', 3],   // Invalid int for BackedIntEnum
    ['p1', null], // Null for non-nullable enum
]);

// ReflectionParameter::isVariadic() exists, but ArgumentPreparer doesn't use it currently.
// For now, variadics aren't handled by the preparer.
