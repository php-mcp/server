<?php

namespace PhpMcp\Server\Tests\Fixtures\Utils;

use PhpMcp\Server\Tests\Fixtures\Enums\BackedIntEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedStringEnum;
use PhpMcp\Server\Tests\Fixtures\Enums\UnitEnum;
use stdClass;

/**
 * Stub class containing methods with various parameter signatures for testing SchemaGenerator.
 */
class SchemaGeneratorFixture
{
    public function noParams(): void {}

    /**
     * Method with simple required types.
     * @param string $p1 String param
     * @param int $p2 Int param
     * @param bool $p3 Bool param
     * @param float $p4 Float param
     * @param array $p5 Array param
     * @param stdClass $p6 Object param
     */
    public function simpleRequired(string $p1, int $p2, bool $p3, float $p4, array $p5, stdClass $p6): void {}

    /**
     * Method with simple optional types with default values.
     * @param string $p1 String param
     * @param int $p2 Int param
     * @param bool $p3 Bool param
     * @param float $p4 Float param
     * @param array $p5 Array param
     * @param stdClass|null $p6 Object param
     */
    public function simpleOptionalDefaults(
        string $p1 = 'default',
        int $p2 = 123,
        bool $p3 = true,
        float $p4 = 1.23,
        array $p5 = ['a', 'b'],
        ?stdClass $p6 = null
    ): void {}

    /**
     * Method with nullable types without explicit defaults.
     * @param string|null $p1 Nullable string
     * @param ?int $p2 Nullable int shorthand
     * @param ?bool $p3 Nullable bool
     */
    public function nullableWithoutDefault(?string $p1, ?int $p2, ?bool $p3): void {}

    /**
     * Method with nullable types WITH explicit null defaults.
     * @param string|null $p1 Nullable string with default
     * @param ?int $p2 Nullable int shorthand with default
     */
    public function nullableWithNullDefault(?string $p1 = null, ?int $p2 = null): void {}

    /**
     * Method with union types.
     * @param string|int $p1 String or Int
     * @param bool|string|null $p2 Bool, String or Null
     */
    public function unionTypes(string|int $p1, bool|null|string $p2): void {}

    /**
     * Method with various array types.
     * @param array $p1 Generic array
     * @param string[] $p2 Array of strings (docblock)
     * @param int[] $p3 Array of integers (docblock)
     * @param array<int, string> $p4 Generic array map (docblock)
     * @param BackedStringEnum[] $p5 Array of enums (docblock)
     * @param ?bool[] $p6 Array of nullable booleans (docblock)
     */
    public function arrayTypes(
        array $p1,
        array $p2,
        array $p3,
        array $p4,
        array $p5,
        array $p6
    ): void {}

    /**
     * Method with various enum types.
     * @param BackedStringEnum $p1 Backed string enum
     * @param BackedIntEnum $p2 Backed int enum
     * @param UnitEnum $p3 Unit enum
     * @param ?BackedStringEnum $p4 Nullable backed string enum
     * @param BackedIntEnum $p5 Optional backed int enum
     * @param UnitEnum|null $p6 Optional unit enum with null default
     */
    public function enumTypes(
        BackedStringEnum $p1,
        BackedIntEnum $p2,
        UnitEnum $p3,
        ?BackedStringEnum $p4,
        BackedIntEnum $p5 = BackedIntEnum::First,
        ?UnitEnum $p6 = null
    ): void {}

    /**
     * Method with variadic parameters.
     * @param string ...$items Variadic strings
     */
    public function variadicParam(string ...$items): void {}

    /**
     * Method with mixed type hint.
     * @param mixed $p1 Mixed type
     * @param mixed $p2 Optional mixed type
     */
    public function mixedType(mixed $p1, mixed $p2 = 'hello'): void {}

    /**
     * Method using only docblocks for type/description.
     * @param string $p1 Only docblock type
     * @param $p2 Only docblock description
     */
    public function docBlockOnly($p1, $p2): void {}

    /**
     * Method with docblock type overriding PHP type hint.
     * @param string $p1 Docblock overrides int
     */
    public function docBlockOverrides(int $p1): void {}

    /**
     * Method with parameters implying formats.
     * @param string $email Email address
     * @param string $url URL string
     * @param string $dateTime ISO Date time string
     */
    public function formatParams(string $email, string $url, string $dateTime): void {}

    // Intersection types might not be directly supported by JSON schema
    // public function intersectionType(MyInterface&MyOtherInterface $p1): void {}
}
