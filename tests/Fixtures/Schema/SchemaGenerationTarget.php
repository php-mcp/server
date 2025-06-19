<?php

namespace PhpMcp\Server\Tests\Fixtures\Schema;

use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Attributes\Schema\Format;
use PhpMcp\Server\Attributes\Schema\ArrayItems;
use PhpMcp\Server\Attributes\Schema\Property;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedIntEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedStringEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\UnitEnum;
use stdClass;

class SchemaGenerationTarget
{
    public function noParamsMethod(): void
    {
    }

    /**
     * Method with simple required types.
     * @param string $pString String param
     * @param int $pInt Int param
     * @param bool $pBool Bool param
     * @param float $pFloat Float param
     * @param array $pArray Array param
     * @param stdClass $pObject Object param
     */
    public function simpleRequiredTypes(string $pString, int $pInt, bool $pBool, float $pFloat, array $pArray, stdClass $pObject): void
    {
    }

    /**
     * Method with simple optional types with default values.
     * @param string $pStringOpt String param with default
     * @param int $pIntOpt Int param with default
     * @param bool $pBoolOpt Bool param with default
     * @param ?float $pFloatOptNullable Float param with default, also nullable
     * @param array $pArrayOpt Array param with default
     * @param ?stdClass $pObjectOptNullable Object param with default null
     */
    public function optionalTypesWithDefaults(
        string $pStringOpt = "hello",
        int $pIntOpt = 123,
        bool $pBoolOpt = true,
        ?float $pFloatOptNullable = 1.23,
        array $pArrayOpt = ['a', 'b'],
        ?stdClass $pObjectOptNullable = null
    ): void {
    }

    /**
     * Nullable types without explicit defaults.
     * @param ?string $pNullableString Nullable string
     * @param int|null $pUnionNullableInt Union nullable int
     */
    public function nullableTypes(?string $pNullableString, ?int $pUnionNullableInt, ?BackedStringEnum $pNullableEnum): void
    {
    }

    /**
     * Union types.
     * @param string|int $pStringOrInt String or Int
     * @param bool|float|null $pBoolOrFloatOrNull Bool, Float or Null
     */
    public function unionTypes(string|int $pStringOrInt, $pBoolOrFloatOrNull): void
    {
    } // PHP 7.x style union in docblock usually

    /**
     * Various array type hints.
     * @param string[] $pStringArray Array of strings (docblock style)
     * @param array<int> $pIntArrayGeneric Array of integers (generic style)
     * @param array<string, mixed> $pAssocArray Associative array
     * @param BackedIntEnum[] $pEnumArray Array of enums
     * @param array{name: string, age: int} $pShapeArray Typed array shape
     * @param array<array{id:int, value:string}> $pArrayOfShapes Array of shapes
     */
    public function arrayTypes(
        array $pStringArray,
        array $pIntArrayGeneric,
        array $pAssocArray,
        array $pEnumArray,
        array $pShapeArray,
        array $pArrayOfShapes
    ): void {
    }

    /**
     * Enum types.
     * @param BackedStringEnum $pBackedStringEnum Backed string enum
     * @param BackedIntEnum $pBackedIntEnum Backed int enum
     * @param UnitEnum $pUnitEnum Unit enum
     */
    public function enumTypes(BackedStringEnum $pBackedStringEnum, BackedIntEnum $pBackedIntEnum, UnitEnum $pUnitEnum): void
    {
    }

    /**
     * Variadic parameters.
     * @param string ...$pVariadicStrings Variadic strings
     */
    public function variadicParams(string ...$pVariadicStrings): void
    {
    }

    /**
     * Mixed type.
     * @param mixed $pMixed Mixed type
     */
    public function mixedType(mixed $pMixed): void
    {
    }

    /**
     * With #[Schema] attributes for enhanced validation.
     * @param string $email With email format.
     * @param int $quantity With numeric constraints.
     * @param string[] $tags With array constraints.
     * @param array $userProfile With object property constraints.
     */
    public function withSchemaAttributes(
        #[Schema(format: Format::EMAIL)]
        string $email,
        #[Schema(minimum: 1, maximum: 100, multipleOf: 5)]
        int $quantity,
        #[Schema(minItems: 1, maxItems: 5, uniqueItems: true, items: new ArrayItems(minLength: 3))]
        array $tags,
        #[Schema(
            properties: [
                new Property(name: 'id', minimum: 1),
                new Property(name: 'username', pattern: '^[a-z0-9_]{3,16}$'),
            ],
            required: ['id', 'username'],
            additionalProperties: false
        )]
        array $userProfile
    ): void {
    }
}
