<?php

namespace PhpMcp\Server\Tests\Fixtures\Utils;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
class TestAttributeOne
{
    public function __construct(public string $value)
    {
    }
}

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
class TestAttributeTwo
{
    public function __construct(public int $number)
    {
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class TestClassOnlyAttribute
{
}


// --- Test Class ---

#[TestClassOnlyAttribute]
#[TestAttributeOne(value: 'class-level')]
class AttributeFixtures
{
    #[TestAttributeOne(value: 'prop-level')]
    public string $propertyOne = 'default';

    #[TestAttributeOne(value: 'method-one')]
    public function methodOne(
        #[TestAttributeOne(value: 'param-one')]
        #[TestAttributeTwo(number: 1)]
        string $param1
    ): void {
    }

    #[TestAttributeOne(value: 'method-two')]
    #[TestAttributeTwo(number: 2)]
    public function methodTwo(
        #[TestAttributeTwo(number: 3)]
        int $paramA
    ): void {
    }

    // Method with no attributes
    public function methodThree(string $unattributedParam): void
    {
    }
}
