<?php

namespace PhpMcp\Server\Tests\Fixtures\General;

use PhpMcp\Server\Tests\Fixtures\Enums\BackedIntEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedStringEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\UnitEnum;
use stdClass;

class VariousTypesHandler
{
    public function noArgsMethod(): array
    {
        return compact([]);
    }

    public function simpleRequiredArgs(string $pString, int $pInt, bool $pBool): array
    {
        return compact('pString', 'pInt', 'pBool');
    }

    public function optionalArgsWithDefaults(
        string $pString = 'default_string',
        int $pInt = 100,
        ?bool $pNullableBool = true,
        float $pFloat = 3.14
    ): array {
        return compact('pString', 'pInt', 'pNullableBool', 'pFloat');
    }

    public function nullableArgsWithoutDefaults(?string $pString, ?int $pInt, ?array $pArray): array
    {
        return compact('pString', 'pInt', 'pArray');
    }

    public function mixedTypeArg(mixed $pMixed): array
    {
        return compact('pMixed');
    }

    public function backedEnumArgs(
        BackedStringEnum $pBackedString,
        BackedIntEnum $pBackedInt,
        ?BackedStringEnum $pNullableBackedString = null,
        BackedIntEnum $pOptionalBackedInt = BackedIntEnum::First
    ): array {
        return compact('pBackedString', 'pBackedInt', 'pNullableBackedString', 'pOptionalBackedInt');
    }

    public function unitEnumArg(UnitEnum $pUnitEnum): array
    {
        return compact('pUnitEnum');
    }

    public function arrayArg(array $pArray): array
    {
        return compact('pArray');
    }

    public function objectArg(stdClass $pObject): array
    {
        return compact('pObject');
    }

    public function variadicArgs(string ...$items): array
    {
        return compact('items');
    }

    /**
     * A comprehensive method for testing various argument types and casting.
     * @param string $strParam A string.
     * @param int $intParam An integer.
     * @param bool $boolProp A boolean.
     * @param float $floatParam A float.
     * @param array $arrayParam An array.
     * @param BackedStringEnum $backedStringEnumParam A backed string enum.
     * @param BackedIntEnum $backedIntEnumParam A backed int enum.
     * @param UnitEnum $unitEnumParam A unit enum (passed as instance).
     * @param string|null $nullableStringParam A nullable string.
     * @param int $optionalIntWithDefaultParam An optional int with default.
     * @param mixed $mixedParam A mixed type.
     * @param stdClass $objectParam An object.
     * @param string $stringForIntCast String that should be cast to int.
     * @param string $stringForFloatCast String that should be cast to float.
     * @param string $stringForBoolTrueCast String that should be cast to bool true.
     * @param string $stringForBoolFalseCast String that should be cast to bool false.
     * @param int $intForStringCast Int that should be cast to string.
     * @param int $intForFloatCast Int that should be cast to float.
     * @param bool $boolForStringCast Bool that should be cast to string.
     * @param string $valueForBackedStringEnum String value for backed string enum.
     * @param int $valueForBackedIntEnum Int value for backed int enum.
     */
    public function comprehensiveArgumentTest(
        string $strParam,
        int $intParam,
        bool $boolProp,
        float $floatParam,
        array $arrayParam,
        BackedStringEnum $backedStringEnumParam,
        BackedIntEnum $backedIntEnumParam,
        UnitEnum $unitEnumParam,
        ?string $nullableStringParam,
        mixed $mixedParam,
        stdClass $objectParam,
        string $stringForIntCast,
        string $stringForFloatCast,
        string $stringForBoolTrueCast,
        string $stringForBoolFalseCast,
        int $intForStringCast,
        int $intForFloatCast,
        bool $boolForStringCast,
        string $valueForBackedStringEnum,
        int $valueForBackedIntEnum,
        int $optionalIntWithDefaultParam = 999,
    ): array {
        return compact(
            'strParam',
            'intParam',
            'boolProp',
            'floatParam',
            'arrayParam',
            'backedStringEnumParam',
            'backedIntEnumParam',
            'unitEnumParam',
            'nullableStringParam',
            'optionalIntWithDefaultParam',
            'mixedParam',
            'objectParam',
            'stringForIntCast',
            'stringForFloatCast',
            'stringForBoolTrueCast',
            'stringForBoolFalseCast',
            'intForStringCast',
            'intForFloatCast',
            'boolForStringCast',
            'valueForBackedStringEnum',
            'valueForBackedIntEnum'
        );
    }

    public function methodCausesTypeError(int $mustBeInt): void
    {
    }
}
