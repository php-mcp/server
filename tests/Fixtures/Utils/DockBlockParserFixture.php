<?php

namespace PhpMcp\Server\Tests\Fixtures\Utils;

/**
 * Test stub for DocBlock array type parsing
 */
class DockBlockParserFixture
{
    /**
     * Method with simple array[] syntax
     *
     * @param string[] $strings Array of strings using [] syntax
     * @param int[] $integers Array of integers using [] syntax
     * @param bool[] $booleans Array of booleans using [] syntax
     * @param float[] $floats Array of floats using [] syntax
     * @param object[] $objects Array of objects using [] syntax
     * @param \DateTime[] $dateTimeInstances Array of DateTime objects
     */
    public function simpleArraySyntax(
        array $strings,
        array $integers,
        array $booleans,
        array $floats,
        array $objects,
        array $dateTimeInstances
    ): void {
    }

    /**
     * Method with array<T> generic syntax
     *
     * @param array<string> $strings Array of strings using generic syntax
     * @param array<int> $integers Array of integers using generic syntax
     * @param array<bool> $booleans Array of booleans using generic syntax
     * @param array<float> $floats Array of floats using generic syntax
     * @param array<object> $objects Array of objects using generic syntax
     * @param array<\DateTime> $dateTimeInstances Array of DateTime objects using generic syntax
     */
    public function genericArraySyntax(
        array $strings,
        array $integers,
        array $booleans,
        array $floats,
        array $objects,
        array $dateTimeInstances
    ): void {
    }

    /**
     * Method with nested array syntax
     *
     * @param array<array<string>> $nestedStringArrays Array of arrays of strings
     * @param array<array<int>> $nestedIntArrays Array of arrays of integers
     * @param string[][] $doubleStringArrays Array of arrays of strings using double []
     * @param int[][] $doubleIntArrays Array of arrays of integers using double []
     */
    public function nestedArraySyntax(
        array $nestedStringArrays,
        array $nestedIntArrays,
        array $doubleStringArrays,
        array $doubleIntArrays
    ): void {
    }

    /**
     * Method with object-like array syntax
     *
     * @param array{name: string, age: int} $person Simple object array with name and age
     * @param array{id: int, title: string, tags: string[]} $article Article with array of tags
     * @param array{user: array{id: int, name: string}, items: array<int>} $order Order with nested user object and array of item IDs
     */
    public function objectArraySyntax(
        array $person,
        array $article,
        array $order
    ): void {
    }
}
