<?php

declare(strict_types=1);

namespace PhpMcp\Server\Attributes;

use Attribute;
use PhpMcp\Server\Attributes\Schema\ArrayItems;
use PhpMcp\Server\Attributes\Schema\Property;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Schema
{
    /** @var Property[] */
    protected array $properties = [];
    
    /**
     * @param string|null $format String format (email, date-time, uri, etc.)
     * @param int|null $minLength Minimum string length
     * @param int|null $maxLength Maximum string length
     * @param string|null $pattern Regular expression pattern
     * @param int|float|null $minimum Minimum numeric value
     * @param int|float|null $maximum Maximum numeric value
     * @param bool|null $exclusiveMinimum Whether minimum is exclusive
     * @param bool|null $exclusiveMaximum Whether maximum is exclusive
     * @param int|float|null $multipleOf Value must be multiple of this number
     * @param ArrayItems|null $items Schema for array items
     * @param int|null $minItems Minimum array items
     * @param int|null $maxItems Maximum array items
     * @param bool|null $uniqueItems Whether array items must be unique
     * @param Property[] $properties Properties for object validation
     * @param string[]|null $required Required properties for objects
     * @param bool|Schema|null $additionalProperties Whether additional properties are allowed
     * @param mixed|null $enum List of allowed values
     * @param mixed|null $default Default value
     */
    public function __construct(
        public ?string $format = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?bool $exclusiveMinimum = null,
        public ?bool $exclusiveMaximum = null,
        public int|float|null $multipleOf = null,
        public ?ArrayItems $items = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        array $properties = [],
        public ?array $required = null,
        public bool|Schema|null $additionalProperties = null,
        public mixed $enum = null,
        public mixed $default = null,
    ) {
        $this->properties = $properties;
    }

    /**
     * Convert to JSON Schema array
     */
    public function toArray(): array
    {
        $schema = [];
        
        // String constraints
        if ($this->format !== null) $schema['format'] = $this->format;
        if ($this->minLength !== null) $schema['minLength'] = $this->minLength;
        if ($this->maxLength !== null) $schema['maxLength'] = $this->maxLength;
        if ($this->pattern !== null) $schema['pattern'] = $this->pattern;
        
        // Numeric constraints
        if ($this->minimum !== null) $schema['minimum'] = $this->minimum;
        if ($this->maximum !== null) $schema['maximum'] = $this->maximum;
        if ($this->exclusiveMinimum !== null) $schema['exclusiveMinimum'] = $this->exclusiveMinimum;
        if ($this->exclusiveMaximum !== null) $schema['exclusiveMaximum'] = $this->exclusiveMaximum;
        if ($this->multipleOf !== null) $schema['multipleOf'] = $this->multipleOf;
        
        // Array constraints
        if ($this->items !== null) $schema['items'] = $this->items->toArray();
        if ($this->minItems !== null) $schema['minItems'] = $this->minItems;
        if ($this->maxItems !== null) $schema['maxItems'] = $this->maxItems;
        if ($this->uniqueItems !== null) $schema['uniqueItems'] = $this->uniqueItems;
        
        // Object constraints
        if (!empty($this->properties)) {
            $props = [];
            foreach ($this->properties as $property) {
                $props[$property->name] = $property->toArray();
            }
            $schema['properties'] = $props;
        }
        
        if ($this->required !== null) $schema['required'] = $this->required;
        
        if ($this->additionalProperties !== null) {
            if ($this->additionalProperties instanceof self) {
                $schema['additionalProperties'] = $this->additionalProperties->toArray();
            } else {
                $schema['additionalProperties'] = $this->additionalProperties;
            }
        }
        
        // General constraints
        if ($this->enum !== null) $schema['enum'] = $this->enum;
        if ($this->default !== null) $schema['default'] = $this->default;
        
        return $schema;
    }
} 