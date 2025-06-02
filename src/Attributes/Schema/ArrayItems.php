<?php

declare(strict_types=1);

namespace PhpMcp\Server\Attributes\Schema;

use PhpMcp\Server\Attributes\Schema;

/**
 * Array item schema - extends Schema to inherit all schema properties
 */
class ArrayItems extends Schema
{
    /**
     * Creates a schema definition for array items
     */
    public function __construct(
        ?string $format = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?bool $exclusiveMinimum = null,
        ?bool $exclusiveMaximum = null,
        int|float|null $multipleOf = null,
        ?ArrayItems $items = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        array $properties = [],
        ?array $required = null,
        bool|Schema|null $additionalProperties = null,
        mixed $enum = null,
        mixed $default = null,
    ) {
        parent::__construct(
            format: $format,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            minimum: $minimum,
            maximum: $maximum,
            exclusiveMinimum: $exclusiveMinimum,
            exclusiveMaximum: $exclusiveMaximum,
            multipleOf: $multipleOf,
            items: $items,
            minItems: $minItems,
            maxItems: $maxItems,
            uniqueItems: $uniqueItems,
            properties: $properties,
            required: $required,
            additionalProperties: $additionalProperties,
            enum: $enum,
            default: $default,
        );
    }
}
