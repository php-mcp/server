<?php

namespace PhpMcp\Server\Tests\Mocks\SupportStubs;

use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Attributes\Schema\ArrayItems;
use PhpMcp\Server\Attributes\Schema\Format;
use PhpMcp\Server\Attributes\Schema\Property;

class SchemaAttributeTestStub
{
    /**
     * Method with string Schema attribute constraints
     *
     * @param string $email Email address with format constraint
     * @param string $password Password with length and pattern constraints
     * @param string $code Simple string with no constraints
     */
    public function stringConstraints(
        #[Schema(format: Format::EMAIL)] string $email,
        #[Schema(minLength: 8, pattern: '^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$')] string $password,
        string $code
    ): void {
    }

    /**
     * Method with numeric Schema attribute constraints
     *
     * @param int $age Age with range constraints
     * @param float $rating Rating with min/max
     * @param int $count Count with multipleOf constraint
     */
    public function numericConstraints(
        #[Schema(minimum: 18, maximum: 120)] 
        int $age,

        #[Schema(minimum: 0, maximum: 5, exclusiveMaximum: true)]
        float $rating,

        #[Schema(minimum: 0, multipleOf: 10)] 
        int $count
    ): void {
    }

    /**
     * Method with array Schema attribute constraints
     *
     * @param string[] $tags Tags with uniqueItems
     * @param int[] $scores Scores with item constraints
     * @param array $mixed Mixed array with no constraints
     */
    public function arrayConstraints(
        #[Schema(minItems: 1, uniqueItems: true)] 
        array $tags,
        #[Schema(minItems: 1, maxItems: 5, items: new ArrayItems(minimum: 0, maximum: 100))] 
        array $scores,
        array $mixed
    ): void {
    }

    /**
     * Method with object Schema attribute constraints
     *
     * @param array $user User object with properties
     * @param array $config Config with additional properties
     */
    public function objectConstraints(
        #[Schema(
            properties: [
                new Property('name', minLength: 2),
                new Property('email', format: Format::EMAIL),
                new Property('age', minimum: 18)
            ],
            required: ['name', 'email']
        )] 
        array $user,

        #[Schema(additionalProperties: true)] 
        array $config
    ): void {
    }

    /**
     * Method with nested Schema attribute constraints
     *
     * @param array $order Order with nested properties
     */
    public function nestedConstraints(
        #[Schema(
            properties: [
                new Property('customer', 
                    properties: [
                        new Property('id', pattern: '^CUS-[0-9]{6}$'),
                        new Property('name', minLength: 2)
                    ],
                    required: ['id']
                ),
                new Property('items', 
                    minItems: 1,
                    items: new ArrayItems(
                        properties: [
                            new Property('product_id', pattern: '^PRD-[0-9]{4}$'),
                            new Property('quantity', minimum: 1)
                        ],
                        required: ['product_id', 'quantity']
                    )
                )
            ],
            required: ['customer', 'items']
        )] array $order
    ): void {
    }

    /**
     * Method to test precedence between PHP type, DocBlock, and Schema attributes
     *
     * @param integer $numericString DocBlock says this is an integer despite string type hint
     * @param string $stringWithFormat DocBlock type matches PHP but Schema adds format
     * @param array<string> $arrayWithItems DocBlock specifies string[] but Schema overrides with number constraints
     */
    public function typePrecedenceTest(
        string $numericString,  // PHP says string

        #[Schema(format: Format::EMAIL)] 
        string $stringWithFormat,  // PHP + Schema

        #[Schema(items: new ArrayItems(minimum: 1, maximum: 100))] 
        array $arrayWithItems  // Schema overrides DocBlock
    ): void {
    }
} 