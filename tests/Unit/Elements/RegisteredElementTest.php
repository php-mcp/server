<?php

namespace PhpMcp\Server\Tests\Unit\Elements;

use Mockery;
use PhpMcp\Server\Elements\RegisteredElement;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedIntEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedStringEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\UnitEnum;
use PhpMcp\Server\Tests\Fixtures\General\VariousTypesHandler;
use Psr\Container\ContainerInterface;
use stdClass;

// --- Test Fixtures for Handler Types ---

class MyInvokableTestHandler
{
    public function __invoke(string $name): string
    {
        return "Hello, {$name}!";
    }
}

class MyStaticMethodTestHandler
{
    public static function myStaticMethod(int $a, int $b): int
    {
        return $a + $b;
    }
}

function my_global_test_function(bool $flag): string
{
    return $flag ? 'on' : 'off';
}


beforeEach(function () {
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->container->shouldReceive('get')->with(VariousTypesHandler::class)->andReturn(new VariousTypesHandler());
});

it('can be constructed as manual or discovered', function () {
    $handler = [VariousTypesHandler::class, 'noArgsMethod'];
    $elManual = new RegisteredElement($handler, true);
    $elDiscovered = new RegisteredElement($handler, false);
    expect($elManual->isManual)->toBeTrue();
    expect($elDiscovered->isManual)->toBeFalse();
    expect($elDiscovered->handler)->toBe($handler);
});

it('prepares arguments in correct order for simple required types', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'simpleRequiredArgs']);
    $args = ['pString' => 'hello', 'pBool' => true, 'pInt' => 123];
    $result = $element->handle($this->container, $args);

    $expectedResult = ['pString' => 'hello', 'pInt' => 123, 'pBool' => true];

    expect($result)->toBe($expectedResult);
});

it('uses default values for missing optional arguments', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'optionalArgsWithDefaults']);

    $result1 = $element->handle($this->container, ['pString' => 'override']);
    expect($result1['pString'])->toBe('override');
    expect($result1['pInt'])->toBe(100);
    expect($result1['pNullableBool'])->toBeTrue();
    expect($result1['pFloat'])->toBe(3.14);

    $result2 = $element->handle($this->container, []);
    expect($result2['pString'])->toBe('default_string');
    expect($result2['pInt'])->toBe(100);
    expect($result2['pNullableBool'])->toBeTrue();
    expect($result2['pFloat'])->toBe(3.14);
});

it('passes null for nullable arguments if not provided', function () {
    $elementNoDefaults = new RegisteredElement([VariousTypesHandler::class, 'nullableArgsWithoutDefaults']);
    $result2 = $elementNoDefaults->handle($this->container, []);
    expect($result2['pString'])->toBeNull();
    expect($result2['pInt'])->toBeNull();
    expect($result2['pArray'])->toBeNull();
});

it('passes null explicitly for nullable arguments', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'nullableArgsWithoutDefaults']);
    $result = $element->handle($this->container, ['pString' => null, 'pInt' => null, 'pArray' => null]);
    expect($result['pString'])->toBeNull();
    expect($result['pInt'])->toBeNull();
    expect($result['pArray'])->toBeNull();
});

it('handles mixed type arguments', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'mixedTypeArg']);
    $obj = new stdClass();
    $testValues = [
        'a string',
        123,
        true,
        null,
        ['an', 'array'],
        $obj
    ];
    foreach ($testValues as $value) {
        $result = $element->handle($this->container, ['pMixed' => $value]);
        expect($result['pMixed'])->toBe($value);
    }
});

it('throws McpServerException for missing required argument', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'simpleRequiredArgs']);
    $element->handle($this->container, ['pString' => 'hello', 'pInt' => 123]);
})->throws(McpServerException::class, 'Missing required argument `pBool`');

dataset('valid_type_casts', [
    'string_from_int'       => ['strParam', 123, '123'],
    'int_from_valid_string' => ['intParam', '456', 456],
    'int_from_neg_string'   => ['intParam', '-10', -10],
    'int_from_float_whole'  => ['intParam', 77.0, 77],
    'bool_from_int_1'       => ['boolProp', 1, true],
    'bool_from_string_true' => ['boolProp', 'true', true],
    'bool_from_string_TRUE' => ['boolProp', 'TRUE', true],
    'bool_from_int_0'       => ['boolProp', 0, false],
    'bool_from_string_false' => ['boolProp', 'false', false],
    'bool_from_string_FALSE' => ['boolProp', 'FALSE', false],
    'float_from_valid_string' => ['floatParam', '7.89', 7.89],
    'float_from_int'        => ['floatParam', 10, 10.0],
    'array_passthrough'     => ['arrayParam', ['x', 'y'], ['x', 'y']],
    'object_passthrough'    => ['objectParam', (object)['a' => 1], (object)['a' => 1]],
    'string_for_int_cast_specific' => ['stringForIntCast', '999', 999],
    'string_for_float_cast_specific' => ['stringForFloatCast', '123.45', 123.45],
    'string_for_bool_true_cast_specific' => ['stringForBoolTrueCast', '1', true],
    'string_for_bool_false_cast_specific' => ['stringForBoolFalseCast', '0', false],
    'int_for_string_cast_specific' => ['intForStringCast', 55, '55'],
    'int_for_float_cast_specific' => ['intForFloatCast', 66, 66.0],
    'bool_for_string_cast_specific' => ['boolForStringCast', true, '1'],
    'backed_string_enum_valid_val' => ['backedStringEnumParam', 'A', BackedStringEnum::OptionA],
    'backed_int_enum_valid_val' => ['backedIntEnumParam', 1, BackedIntEnum::First],
    'unit_enum_valid_val' => ['unitEnumParam', 'Yes', UnitEnum::Yes],
]);

it('casts argument types correctly for valid inputs (comprehensive)', function (string $paramName, mixed $inputValue, mixed $expectedValue) {
    $element = new RegisteredElement([VariousTypesHandler::class, 'comprehensiveArgumentTest']);

    $allArgs = [
        'strParam' => 'default string',
        'intParam' => 0,
        'boolProp' => false,
        'floatParam' => 0.0,
        'arrayParam' => [],
        'backedStringEnumParam' => BackedStringEnum::OptionA,
        'backedIntEnumParam' => BackedIntEnum::First,
        'unitEnumParam' => 'Yes',
        'nullableStringParam' => null,
        'mixedParam' => 'default mixed',
        'objectParam' => new stdClass(),
        'stringForIntCast' => '0',
        'stringForFloatCast' => '0.0',
        'stringForBoolTrueCast' => 'false',
        'stringForBoolFalseCast' => 'true',
        'intForStringCast' => 0,
        'intForFloatCast' => 0,
        'boolForStringCast' => false,
        'valueForBackedStringEnum' => 'A',
        'valueForBackedIntEnum' => 1,
    ];
    $testArgs = array_merge($allArgs, [$paramName => $inputValue]);

    $result = $element->handle($this->container, $testArgs);
    expect($result[$paramName])->toEqual($expectedValue);
})->with('valid_type_casts');


dataset('invalid_type_casts', [
    'int_from_alpha_string' => ['intParam', 'abc', '/Cannot cast value to integer/i'],
    'int_from_float_non_whole' => ['intParam', 12.3, '/Cannot cast value to integer/i'],
    'bool_from_string_random' => ['boolProp', 'random', '/Cannot cast value to boolean/i'],
    'bool_from_int_invalid' => ['boolProp', 2, '/Cannot cast value to boolean/i'],
    'float_from_alpha_string' => ['floatParam', 'xyz', '/Cannot cast value to float/i'],
    'array_from_string' => ['arrayParam', 'not_an_array', '/Cannot cast value to array/i'],
    'backed_string_enum_invalid_val' => ['backedStringEnumParam', 'Z', "/Invalid value 'Z' for backed enum .*BackedStringEnum/i"],
    'backed_int_enum_invalid_val' => ['backedIntEnumParam', 99, "/Invalid value '99' for backed enum .*BackedIntEnum/i"],
    'unit_enum_invalid_string_val' => ['unitEnumParam', 'Maybe', "/Invalid value 'Maybe' for unit enum .*UnitEnum/i"],
]);

it('throws McpServerException for invalid type casting', function (string $paramName, mixed $invalidValue, string $expectedMsgRegex) {
    $element = new RegisteredElement([VariousTypesHandler::class, 'comprehensiveArgumentTest']);
    $allArgs = [ /* fill with defaults as in valid_type_casts */
        'strParam' => 's',
        'intParam' => 1,
        'boolProp' => true,
        'floatParam' => 1.1,
        'arrayParam' => [],
        'backedStringEnumParam' => BackedStringEnum::OptionA,
        'backedIntEnumParam' => BackedIntEnum::First,
        'unitEnumParam' => UnitEnum::Yes,
        'nullableStringParam' => null,
        'mixedParam' => 'mix',
        'objectParam' => new stdClass(),
        'stringForIntCast' => '0',
        'stringForFloatCast' => '0.0',
        'stringForBoolTrueCast' => 'false',
        'stringForBoolFalseCast' => 'true',
        'intForStringCast' => 0,
        'intForFloatCast' => 0,
        'boolForStringCast' => false,
        'valueForBackedStringEnum' => 'A',
        'valueForBackedIntEnum' => 1,
    ];
    $testArgs = array_merge($allArgs, [$paramName => $invalidValue]);

    try {
        $element->handle($this->container, $testArgs);
    } catch (McpServerException $e) {
        expect($e->getMessage())->toMatch($expectedMsgRegex);
    }
})->with('invalid_type_casts');

it('casts to BackedStringEnum correctly', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'backedEnumArgs']);
    $result = $element->handle($this->container, ['pBackedString' => 'A', 'pBackedInt' => 1]);
    expect($result['pBackedString'])->toBe(BackedStringEnum::OptionA);
});

it('throws for invalid BackedStringEnum value', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'backedEnumArgs']);
    $element->handle($this->container, ['pBackedString' => 'Invalid', 'pBackedInt' => 1]);
})->throws(McpServerException::class, "Invalid value 'Invalid' for backed enum");

it('casts to BackedIntEnum correctly', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'backedEnumArgs']);
    $result = $element->handle($this->container, ['pBackedString' => 'A', 'pBackedInt' => 2]);
    expect($result['pBackedInt'])->toBe(BackedIntEnum::Second);
});

it('throws for invalid BackedIntEnum value', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'backedEnumArgs']);
    $element->handle($this->container, ['pBackedString' => 'A', 'pBackedInt' => 999]);
})->throws(McpServerException::class, "Invalid value '999' for backed enum");

it('casts to UnitEnum correctly', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'unitEnumArg']);
    $result = $element->handle($this->container, ['pUnitEnum' => 'Yes']);
    expect($result['pUnitEnum'])->toBe(UnitEnum::Yes);
});

it('throws for invalid UnitEnum value', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'unitEnumArg']);
    $element->handle($this->container, ['pUnitEnum' => 'Invalid']);
})->throws(McpServerException::class, "Invalid value 'Invalid' for unit enum");


it('throws ReflectionException if handler method does not exist', function () {
    $element = new RegisteredElement([VariousTypesHandler::class, 'nonExistentMethod']);
    $element->handle($this->container, []);
})->throws(\ReflectionException::class, "VariousTypesHandler::nonExistentMethod() does not exist");


describe('Handler Types', function () {
    it('handles invokable class handler', function () {
        $this->container->shouldReceive('get')
            ->with(MyInvokableTestHandler::class)
            ->andReturn(new MyInvokableTestHandler());

        $element = new RegisteredElement(MyInvokableTestHandler::class);
        $result = $element->handle($this->container, ['name' => 'World']);

        expect($result)->toBe('Hello, World!');
    });

    it('handles closure handler', function () {
        $closure = function (string $a, string $b) {
            return $a . $b;
        };
        $element = new RegisteredElement($closure);
        $result = $element->handle($this->container, ['a' => 'foo', 'b' => 'bar']);
        expect($result)->toBe('foobar');
    });

    it('handles static method handler', function () {
        $handler = [MyStaticMethodTestHandler::class, 'myStaticMethod'];
        $element = new RegisteredElement($handler);
        $result = $element->handle($this->container, ['a' => 5, 'b' => 10]);
        expect($result)->toBe(15);
    });

    it('handles global function name handler', function () {
        $handler = 'PhpMcp\Server\Tests\Unit\Elements\my_global_test_function';
        $element = new RegisteredElement($handler);
        $result = $element->handle($this->container, ['flag' => true]);
        expect($result)->toBe('on');
    });
});
