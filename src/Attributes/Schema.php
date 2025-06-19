<?php

declare(strict_types=1);

namespace PhpMcp\Server\Attributes;

use Attribute;

/**
 * Defines a JSON Schema for a method's input or an individual parameter.
 *
 * When used at the method level, it describes an object schema where properties
 * correspond to the method's parameters.
 *
 * When used at the parameter level, it describes the schema for that specific parameter.
 * If 'type' is omitted at the parameter level, it will be inferred.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class Schema
{
    /**
     * The complete JSON schema array.
     * If provided, it takes precedence over individual properties like $type, $properties, etc.
     */
    public ?array $definition = null;

    /**
     * Alternatively, provide individual top-level schema keywords.
     * These are used if $definition is null.
     */
    public ?string $type = null;
    public ?string $description = null;
    public mixed $default = null;
    public ?array $enum = null; // list of allowed values
    public ?string $format = null; // e.g., 'email', 'date-time'

    // Constraints for string
    public ?int $minLength = null;
    public ?int $maxLength = null;
    public ?string $pattern = null;

    // Constraints for number/integer
    public int|float|null $minimum = null;
    public int|float|null $maximum = null;
    public ?bool $exclusiveMinimum = null;
    public ?bool $exclusiveMaximum = null;
    public int|float|null $multipleOf = null;

    // Constraints for array
    public ?array $items = null; // JSON schema for array items
    public ?int $minItems = null;
    public ?int $maxItems = null;
    public ?bool $uniqueItems = null;

    // Constraints for object (primarily used when Schema is on a method or an object-typed parameter)
    public ?array $properties = null; // [propertyName => [schema array], ...]
    public ?array $required = null;   // [propertyName, ...]
    public bool|array|null $additionalProperties = null; // true, false, or a schema array

    /**
     * @param array|null $definition A complete JSON schema array. If provided, other parameters are ignored.
     * @param Type|null $type The JSON schema type.
     * @param string|null $description Description of the element.
     * @param array|null $enum Allowed enum values.
     * @param string|null $format String format (e.g., 'date-time', 'email').
     * @param int|null $minLength Minimum length for strings.
     * @param int|null $maxLength Maximum length for strings.
     * @param string|null $pattern Regex pattern for strings.
     * @param int|float|null $minimum Minimum value for numbers/integers.
     * @param int|float|null $maximum Maximum value for numbers/integers.
     * @param bool|null $exclusiveMinimum Exclusive minimum.
     * @param bool|null $exclusiveMaximum Exclusive maximum.
     * @param int|float|null $multipleOf Must be a multiple of this value.
     * @param array|null $items JSON Schema for items if type is 'array'.
     * @param int|null $minItems Minimum items for an array.
     * @param int|null $maxItems Maximum items for an array.
     * @param bool|null $uniqueItems Whether array items must be unique.
     * @param array|null $properties Property definitions if type is 'object'. [name => schema_array].
     * @param array|null $required List of required properties for an object.
     * @param bool|array|null $additionalProperties Policy for additional properties in an object.
     */
    public function __construct(
        ?array $definition = null,
        ?string $type = null,
        ?string $description = null,
        ?array $enum = null,
        ?string $format = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?bool $exclusiveMinimum = null,
        ?bool $exclusiveMaximum = null,
        int|float|null $multipleOf = null,
        ?array $items = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        ?array $properties = null,
        ?array $required = null,
        bool|array|null $additionalProperties = null
    ) {
        if ($definition !== null) {
            $this->definition = $definition;
        } else {
            $this->type = $type;
            $this->description = $description;
            $this->enum = $enum;
            $this->format = $format;
            $this->minLength = $minLength;
            $this->maxLength = $maxLength;
            $this->pattern = $pattern;
            $this->minimum = $minimum;
            $this->maximum = $maximum;
            $this->exclusiveMinimum = $exclusiveMinimum;
            $this->exclusiveMaximum = $exclusiveMaximum;
            $this->multipleOf = $multipleOf;
            $this->items = $items;
            $this->minItems = $minItems;
            $this->maxItems = $maxItems;
            $this->uniqueItems = $uniqueItems;
            $this->properties = $properties;
            $this->required = $required;
            $this->additionalProperties = $additionalProperties;
        }
    }

    /**
     * Converts the attribute's definition to a JSON schema array.
     */
    public function toArray(): array
    {
        if ($this->definition !== null) {
            return [
                'definition' => $this->definition,
            ];
        }

        $schema = [];
        if ($this->type !== null) {
            $schema['type'] = $this->type;
        }
        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }
        if ($this->enum !== null) {
            $schema['enum'] = $this->enum;
        }
        if ($this->format !== null) {
            $schema['format'] = $this->format;
        }

        // String
        if ($this->minLength !== null) {
            $schema['minLength'] = $this->minLength;
        }
        if ($this->maxLength !== null) {
            $schema['maxLength'] = $this->maxLength;
        }
        if ($this->pattern !== null) {
            $schema['pattern'] = $this->pattern;
        }

        // Numeric
        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }
        if ($this->exclusiveMinimum !== null) {
            $schema['exclusiveMinimum'] = $this->exclusiveMinimum;
        }
        if ($this->exclusiveMaximum !== null) {
            $schema['exclusiveMaximum'] = $this->exclusiveMaximum;
        }
        if ($this->multipleOf !== null) {
            $schema['multipleOf'] = $this->multipleOf;
        }

        // Array
        if ($this->items !== null) {
            $schema['items'] = $this->items;
        }
        if ($this->minItems !== null) {
            $schema['minItems'] = $this->minItems;
        }
        if ($this->maxItems !== null) {
            $schema['maxItems'] = $this->maxItems;
        }
        if ($this->uniqueItems !== null) {
            $schema['uniqueItems'] = $this->uniqueItems;
        }

        // Object
        if ($this->properties !== null) {
            $schema['properties'] = $this->properties;
        }
        if ($this->required !== null) {
            $schema['required'] = $this->required;
        }
        if ($this->additionalProperties !== null) {
            $schema['additionalProperties'] = $this->additionalProperties;
        }

        return $schema;
    }
}
