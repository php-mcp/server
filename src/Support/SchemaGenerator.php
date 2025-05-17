<?php

namespace PhpMcp\Server\Support;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use PhpMcp\Server\Attributes\Schema;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use stdClass;

/**
 * Generates JSON Schema for method parameters.
 */
class SchemaGenerator
{
    private DocBlockParser $docBlockParser;

    public function __construct(DocBlockParser $docBlockParser)
    {
        $this->docBlockParser = $docBlockParser;
    }

    /**
     * Generates a JSON Schema object (as a PHP array) for a method's parameters.
     *
     * @return array<string, mixed>
     */
    public function fromMethodParameters(ReflectionMethod $method): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        $docComment = $method->getDocComment() ?: null;
        $docBlock = $this->docBlockParser->parseDocBlock($docComment);
        $parametersInfo = $this->parseParametersInfo($method, $docBlock);

        foreach ($parametersInfo as $paramInfo) {
            $name = $paramInfo['name'];
            $typeString = $paramInfo['type_string'];
            $description = $paramInfo['description'];
            $required = $paramInfo['required'];
            $allowsNull = $paramInfo['allows_null'];
            $defaultValue = $paramInfo['default_value'];
            $hasDefault = $paramInfo['has_default'];
            $reflectionType = $paramInfo['reflection_type_object'];
            $isVariadic = $paramInfo['is_variadic'];
            $schemaConstraints = $paramInfo['schema_constraints'] ?? [];

            $paramSchema = [];

            if ($isVariadic) {
                $paramSchema['type'] = 'array';
                if ($description) {
                    $paramSchema['description'] = $description;
                }
                $itemJsonTypes = $this->mapPhpTypeToJsonSchemaType($typeString);
                $nonNullItemTypes = array_filter($itemJsonTypes, fn ($t) => $t !== 'null');
                if (count($nonNullItemTypes) === 1) {
                    $paramSchema['items'] = ['type' => $nonNullItemTypes[0]];
                }
            } else {
                $jsonTypes = $this->mapPhpTypeToJsonSchemaType($typeString);

                if ($allowsNull && strtolower($typeString) !== 'mixed' && ! in_array('null', $jsonTypes)) {
                    $jsonTypes[] = 'null';
                }

                if (count($jsonTypes) > 1) {
                    sort($jsonTypes);
                }

                $nonNullTypes = array_filter($jsonTypes, fn ($t) => $t !== 'null');
                if (count($jsonTypes) === 1) {
                    $paramSchema['type'] = $jsonTypes[0];
                } elseif (count($jsonTypes) > 1) {
                    $paramSchema['type'] = $jsonTypes;
                } else {
                    // If $jsonTypes is still empty (meaning original type was 'mixed'),
                    // DO NOTHING - omitting 'type' implies any type in JSON Schema.
                }

                if ($description) {
                    $paramSchema['description'] = $description;
                }

                if ($hasDefault && ! $required) {
                    $paramSchema['default'] = $defaultValue;
                }

                // Handle enums (PHP 8.1+)
                if ($reflectionType instanceof ReflectionNamedType && ! $reflectionType->isBuiltin() && function_exists('enum_exists') && enum_exists($reflectionType->getName())) {
                    $enumClass = $reflectionType->getName();
                    if (method_exists($enumClass, 'cases')) { // Ensure it's actually an enum
                        $isBacked = ! empty($enumClass::cases()) && isset($enumClass::cases()[0]->value);
                        $enumReflection = new ReflectionEnum($enumClass);
                        $backingTypeReflection = $enumReflection->getBackingType();

                        if ($isBacked && $backingTypeReflection instanceof ReflectionNamedType) {
                            $paramSchema['enum'] = array_column($enumClass::cases(), 'value');
                            $jsonBackingType = match ($backingTypeReflection->getName()) {
                                'int' => 'integer',
                                'string' => 'string',
                                default => null, // Should not happen for valid backed enums
                            };

                            if ($jsonBackingType) {
                                // Ensure schema type matches backing type, considering nullability
                                if (isset($paramSchema['type']) && is_array($paramSchema['type']) && in_array('null', $paramSchema['type'])) {
                                    $paramSchema['type'] = [$jsonBackingType, 'null'];
                                } else {
                                    $paramSchema['type'] = $jsonBackingType;
                                }
                            }
                        } else {
                            // Non-backed enum - use names as enum values
                            $paramSchema['enum'] = array_column($enumClass::cases(), 'name');
                            // Ensure schema type is string, considering nullability
                            if (isset($paramSchema['type']) && is_array($paramSchema['type']) && in_array('null', $paramSchema['type'])) {
                                $paramSchema['type'] = ['string', 'null'];
                            } else {
                                $paramSchema['type'] = 'string';
                            }
                        }
                    }
                }

                // TODO: Revisit format inference or add explicit @schema docblock tag for formats in a future version.
                // For now, parameters typed as 'string' will not have a 'format' keyword automatically added.
                // Users needing specific string format validation (date-time, email, uri, regex pattern)
                // would need to perform that validation within their tool/resource handler method.

                // Handle array items type if possible
                if (isset($paramSchema['type'])) {
                    $schemaType = is_array($paramSchema['type']) ? (in_array('array', $paramSchema['type']) ? 'array' : null) : $paramSchema['type'];
                    
                    // Special handling for object-like arrays using array{} syntax
                    if (preg_match('/^array\s*{/i', $typeString)) {
                        $objectSchema = $this->inferArrayItemsType($typeString);
                        if (is_array($objectSchema) && isset($objectSchema['properties'])) {
                            // Override the type and merge in the properties
                            $paramSchema = array_merge($paramSchema, $objectSchema);
                            // Ensure type is object
                            $paramSchema['type'] = $allowsNull ? ['object', 'null'] : 'object';
                        }
                    }
                    // Handle regular arrays
                    elseif (in_array('array', $this->mapPhpTypeToJsonSchemaType($typeString))) {
                        $itemsType = $this->inferArrayItemsType($typeString);
                        if ($itemsType !== 'any') {
                            if (is_string($itemsType)) {
                                $paramSchema['items'] = ['type' => $itemsType];
                            } else {
                                // Handle complex array item types (for nested arrays and object types)
                                if (!isset($itemsType['type']) && isset($itemsType['properties'])) {
                                    // This is an object schema from array{} syntax
                                    $itemsType = array_merge(['type' => 'object'], $itemsType);
                                }
                                $paramSchema['items'] = $itemsType;
                            }
                        }
                        // Ensure the main type is array, potentially adding null
                        if ($allowsNull) {
                            $paramSchema['type'] = ['array', 'null'];
                            sort($paramSchema['type']);
                        } else {
                            $paramSchema['type'] = 'array'; // Just array if null not allowed
                        }
                    }
                }
            }

            // Merge constraints from Schema attribute
            if (!empty($schemaConstraints)) {
                // Special handling for 'type' to avoid overriding detected type
                if (isset($schemaConstraints['type']) && isset($paramSchema['type'])) {
                    if (is_array($paramSchema['type']) && !is_array($schemaConstraints['type'])) {
                        if (!in_array($schemaConstraints['type'], $paramSchema['type'])) {
                            $paramSchema['type'][] = $schemaConstraints['type'];
                            sort($paramSchema['type']);
                        }
                    } elseif (is_array($schemaConstraints['type']) && !is_array($paramSchema['type'])) {
                        if (!in_array($paramSchema['type'], $schemaConstraints['type'])) {
                            $schemaConstraints['type'][] = $paramSchema['type'];
                            sort($schemaConstraints['type']);
                            $paramSchema['type'] = $schemaConstraints['type'];
                        }
                    }
                    // Remove 'type' to avoid overwriting in the array_merge
                    unset($schemaConstraints['type']);
                }

                // Now merge the rest of the schema constraints
                $paramSchema = array_merge($paramSchema, $schemaConstraints);
            }

            $schema['properties'][$name] = $paramSchema;

            if ($required) {
                $schema['required'][] = $name;
            }
        }

        if (empty($schema['properties'])) {
            // Keep properties object even if empty, per spec
            $schema['properties'] = new stdClass;
        }
        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Parses detailed information about a method's parameters.
     *
     * @return array<int, array{
     *     name: string,
     *     doc_block_tag: Param|null,
     *     reflection_param: ReflectionParameter,
     *     reflection_type_object: ReflectionType|null,
     *     type_string: string,
     *     description: string|null,
     *     required: bool,
     *     allows_null: bool,
     *     default_value: mixed|null,
     *     has_default: bool,
     *     is_variadic: bool,
     *     schema_constraints: array<string, mixed>
     * }>
     */
    private function parseParametersInfo(ReflectionMethod $method, ?DocBlock $docBlock): array
    {
        $paramTags = $this->docBlockParser->getParamTags($docBlock);
        $parametersInfo = [];
        
        // Extract method-level schema constraints (for all parameters)
        $methodSchemaConstraints = $this->extractSchemaConstraintsFromAttributes($method);

        foreach ($method->getParameters() as $rp) {
            $paramName = $rp->getName();
            $paramTag = $paramTags['$'.$paramName] ?? null;

            $reflectionType = $rp->getType();
            $typeString = $this->getParameterTypeString($rp, $paramTag);
            $description = $this->docBlockParser->getParamDescription($paramTag);
            $hasDefault = $rp->isDefaultValueAvailable();
            $defaultValue = $hasDefault ? $rp->getDefaultValue() : null;
            $isVariadic = $rp->isVariadic();

            // Extract schema constraints from parameter attributes
            // Parameter attributes override method attributes
            $paramSchemaConstraints = $this->extractSchemaConstraintsFromAttributes($rp);
            $schemaConstraints = !empty($paramSchemaConstraints) 
                ? $paramSchemaConstraints 
                : $methodSchemaConstraints;

            // If the default value is a BackedEnum, use its scalar value for JSON schema
            if ($hasDefault && $defaultValue instanceof \BackedEnum) {
                $defaultValue = $defaultValue->value;
            }

            $allowsNull = false;
            if ($reflectionType && $reflectionType->allowsNull()) {
                $allowsNull = true;
            } elseif ($hasDefault && $defaultValue === null) {
                $allowsNull = true;
            } elseif (stripos($typeString, 'null') !== false || strtolower($typeString) === 'mixed') {
                $allowsNull = true;
            }

            $parametersInfo[] = [
                'name' => $paramName,
                'doc_block_tag' => $paramTag,
                'reflection_param' => $rp,
                'reflection_type_object' => $reflectionType,
                'type_string' => $typeString,
                'description' => $description,
                'required' => ! $rp->isOptional(),
                'allows_null' => $allowsNull,
                'default_value' => $defaultValue,
                'has_default' => $hasDefault,
                'is_variadic' => $isVariadic,
                'schema_constraints' => $schemaConstraints,
            ];
        }

        return $parametersInfo;
    }

    /**
     * Extract schema constraints from attributes.
     *
     * @param ReflectionParameter|ReflectionMethod $reflection The reflection object to extract schema constraints from
     * @return array<string, mixed> The extracted schema constraints
     */
    private function extractSchemaConstraintsFromAttributes(ReflectionParameter|ReflectionMethod $reflection): array
    {
        $constraints = [];
        
        if (method_exists($reflection, 'getAttributes')) { // PHP 8+ check
            $schemaAttrs = $reflection->getAttributes(Schema::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($schemaAttrs)) {
                $schemaAttr = $schemaAttrs[0]->newInstance();
                $constraints = $schemaAttr->toArray();
            }
        }
        
        return $constraints;
    }

    /**
     * Determines the type string for a parameter, prioritizing DocBlock.
     */
    private function getParameterTypeString(ReflectionParameter $rp, ?Param $paramTag): string
    {
        $docBlockType = $this->docBlockParser->getParamTypeString($paramTag);
        $isDocBlockTypeGeneric = false;

        if ($docBlockType !== null) {
            if (in_array(strtolower($docBlockType), ['mixed', 'unknown', ''])) {
                $isDocBlockTypeGeneric = true;
            }
        } else {
            $isDocBlockTypeGeneric = true; // No tag or no type in tag implies generic
        }

        $reflectionType = $rp->getType();
        $reflectionTypeString = null;
        if ($reflectionType) {
            $reflectionTypeString = $this->getTypeStringFromReflection($reflectionType, $rp->allowsNull());
        }

        // Prioritize Reflection if DocBlock type is generic AND Reflection provides a more specific type
        if ($isDocBlockTypeGeneric && $reflectionTypeString !== null && $reflectionTypeString !== 'mixed') {
            return $reflectionTypeString;
        }

        // Otherwise, use the DocBlock type if it was valid and non-generic
        if ($docBlockType !== null && ! $isDocBlockTypeGeneric) {
            // Consider if DocBlock adds nullability missing from reflection
            if (stripos($docBlockType, 'null') !== false && $reflectionTypeString && stripos($reflectionTypeString, 'null') === false && ! str_ends_with($reflectionTypeString, '|null')) {
                // If reflection didn't capture null, but docblock did, append |null (if not already mixed)
                if ($reflectionTypeString !== 'mixed') {
                    return $reflectionTypeString.'|null';
                }
            }

            return $docBlockType;
        }

        // Fallback to Reflection type even if it was generic ('mixed')
        if ($reflectionTypeString !== null) {
            return $reflectionTypeString;
        }

        // Default to 'mixed' if nothing else found
        return 'mixed';
    }

    /**
     * Converts a ReflectionType object into a type string representation.
     */
    private function getTypeStringFromReflection(?ReflectionType $type, bool $nativeAllowsNull): string
    {
        if ($type === null) {
            return 'mixed'; // Or should it be null? MCP often uses 'mixed' for untyped. Let's stick to mixed for consistency.
        }

        $types = [];
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                $types[] = $this->getTypeStringFromReflection($innerType, $innerType->allowsNull());
            }
            if ($nativeAllowsNull) {
                $types = array_filter($types, fn ($t) => strtolower($t) !== 'null');
            }
            $typeString = implode('|', array_unique(array_filter($types)));

        } elseif ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $innerType) {
                $types[] = $this->getTypeStringFromReflection($innerType, false);
            }
            $typeString = implode('&', array_unique(array_filter($types)));
        } elseif ($type instanceof ReflectionNamedType) {
            $typeString = $type->getName();
        } else {
            return 'mixed'; // Fallback for unknown ReflectionType implementations
        }

        $typeString = match (strtolower($typeString)) {
            'bool' => 'boolean',
            'int' => 'integer',
            'float', 'double' => 'number',
            'str' => 'string',
            default => $typeString,
        };

        $isNullable = $nativeAllowsNull;
        if ($type instanceof ReflectionNamedType && $type->getName() === 'mixed') {
            $isNullable = true;
        }

        if ($type instanceof ReflectionUnionType && ! $nativeAllowsNull) {
            foreach ($type->getTypes() as $innerType) {
                if ($innerType instanceof ReflectionNamedType && strtolower($innerType->getName()) === 'null') {
                    $isNullable = true;
                    break;
                }
            }
        }

        if ($isNullable && $typeString !== 'mixed' && stripos($typeString, 'null') === false) {
            if (! str_ends_with($typeString, '|null') && ! str_ends_with($typeString, '&null')) {
                $typeString .= '|null';
            }
        }

        // Remove leading backslash from class names, but handle built-ins like 'int' or unions like 'int|string'
        if (str_contains($typeString, '\\')) {
            $parts = preg_split('/([|&])/', $typeString, -1, PREG_SPLIT_DELIM_CAPTURE);
            $processedParts = array_map(fn ($part) => str_starts_with($part, '\\') ? ltrim($part, '\\') : $part, $parts);
            $typeString = implode('', $processedParts);
        }

        return $typeString ?: 'mixed';
    }

    /**
     * Maps a PHP type string (potentially a union) to an array of JSON Schema type names.
     *
     * @return list<string> JSON schema types: "string", "integer", "number", "boolean", "array", "object", "null", "any" (custom placeholder)
     */
    private function mapPhpTypeToJsonSchemaType(string $phpTypeString): array
    {
        $normalizedType = strtolower(trim($phpTypeString));

        // PRIORITY 1: Check for array{} syntax which should be treated as object
        if (preg_match('/^array\s*{/i', $normalizedType)) {
            return ['object'];
        }
        
        // PRIORITY 2: Check for array syntax first (T[] or generics)
        if (str_contains($normalizedType, '[]') || 
            preg_match('/^(array|list|iterable|collection)</i', $normalizedType)) {
            return ['array'];
        }

        // PRIORITY 3: Handle unions (recursive)
        if (str_contains($normalizedType, '|')) {
            $types = explode('|', $normalizedType);
            $jsonTypes = [];
            foreach ($types as $type) {
                $mapped = $this->mapPhpTypeToJsonSchemaType(trim($type));
                $jsonTypes = array_merge($jsonTypes, $mapped);
            }

            return array_values(array_unique($jsonTypes));
        }

        // PRIORITY 4: Handle simple built-in types
        return match ($normalizedType) {
            'string', 'scalar' => ['string'],
            '?string' => ['null', 'string'],
            'int', 'integer' => ['integer'],
            '?int', '?integer' => ['null', 'integer'],
            'float', 'double', 'number' => ['number'],
            '?float', '?double', '?number' => ['null', 'number'],
            'bool', 'boolean' => ['boolean'],
            '?bool', '?boolean' => ['null', 'boolean'],
            'array' => ['array'], // Catch native 'array' hint if not caught by generics/[]
            '?array' => ['null', 'array'],
            'object', 'stdclass' => ['object'], // Catch native 'object' hint
            '?object', '?stdclass' => ['null', 'object'],
            'null' => ['null'],
            'resource', 'callable' => ['object'], // Represent these complex types as object
            'mixed' => [], // Omit type for mixed
            'void', 'never' => [], // Not applicable for parameters
            default => ['object'], // Fallback: Treat unknown non-namespaced words as object
        };
    }

    /**
     * Infers the 'items' schema type for an array based on DocBlock type hints.
     * Returns 'any' if type cannot be determined.
     */
    private function inferArrayItemsType(string $phpTypeString): string|array
    {
        $normalizedType = trim($phpTypeString);
        
        // Case 1: Simple T[] syntax (e.g., string[], int[], bool[], etc.)
        if (preg_match('/^(\\??)([\w\\\\]+)\\s*\\[\\]$/i', $normalizedType, $matches)) {
            $itemType = strtolower($matches[2]);
            return $this->mapSimpleTypeToJsonSchema($itemType);
        }
        
        // Case 2: Generic array<T> syntax (e.g., array<string>, array<int>, etc.)
        if (preg_match('/^(\\??)array\s*<\s*([\w\\\\|]+)\s*>$/i', $normalizedType, $matches)) {
            $itemType = strtolower($matches[2]);
            return $this->mapSimpleTypeToJsonSchema($itemType);
        }
        
        // Case 3: Nested array<array<T>> syntax or T[][] syntax
        if (preg_match('/^(\\??)array\s*<\s*array\s*<\s*([\w\\\\|]+)\s*>\s*>$/i', $normalizedType, $matches) ||
            preg_match('/^(\\??)([\w\\\\]+)\s*\[\]\[\]$/i', $normalizedType, $matches)) {
            $innerType = $this->mapSimpleTypeToJsonSchema(isset($matches[2]) ? strtolower($matches[2]) : 'any');
            // Return a schema for array with items being arrays
            return [
                'type' => 'array',
                'items' => [
                    'type' => $innerType
                ]
            ];
        }
        
        // Case 4: Object-like array syntax (e.g., array{name: string, age: int})
        if (preg_match('/^(\\??)array\s*\{(.+)\}$/is', $normalizedType, $matches)) {
            return $this->parseObjectLikeArray($matches[2]);
        }

        // No match or unsupported syntax
        return 'any';
    }
    
    /**
     * Parses object-like array syntax into a JSON Schema object
     */
    private function parseObjectLikeArray(string $propertiesStr): array
    {
        $properties = [];
        $required = [];
        
        // Parse properties from the string, handling nested structures
        $depth = 0;
        $currentProp = '';
        $buffer = '';
        
        for ($i = 0; $i < strlen($propertiesStr); $i++) {
            $char = $propertiesStr[$i];
            
            // Track nested braces
            if ($char === '{') {
                $depth++;
                $buffer .= $char;
            }
            elseif ($char === '}') {
                $depth--;
                $buffer .= $char;
            }
            // Property separator (comma)
            elseif ($char === ',' && $depth === 0) {
                // Process the completed property
                $this->parsePropertyDefinition(trim($buffer), $properties, $required);
                $buffer = '';
            }
            else {
                $buffer .= $char;
            }
        }
        
        // Process the last property
        if (!empty($buffer)) {
            $this->parsePropertyDefinition(trim($buffer), $properties, $required);
        }
        
        if (!empty($properties)) {
            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required
            ];
        }
        
        return ['type' => 'object'];
    }
    
    /**
     * Parses a single property definition from an object-like array syntax
     */
    private function parsePropertyDefinition(string $propDefinition, array &$properties, array &$required): void
    {
        // Match property name and type
        if (preg_match('/^([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*:\s*(.+)$/i', $propDefinition, $matches)) {
            $propName = $matches[1];
            $propType = trim($matches[2]);
            
            // Add to required properties
            $required[] = $propName;
            
            // Check for nested array{} syntax
            if (preg_match('/^array\s*\{(.+)\}$/is', $propType, $nestedMatches)) {
                $nestedSchema = $this->parseObjectLikeArray($nestedMatches[1]);
                $properties[$propName] = $nestedSchema;
            }
            // Check for array<T> or T[] syntax
            elseif (preg_match('/^array\s*<\s*([\w\\\\|]+)\s*>$/i', $propType, $arrayMatches) ||
                   preg_match('/^([\w\\\\]+)\s*\[\]$/i', $propType, $arrayMatches)) {
                $itemType = $arrayMatches[1] ?? 'any';
                $properties[$propName] = [
                    'type' => 'array',
                    'items' => [
                        'type' => $this->mapSimpleTypeToJsonSchema($itemType)
                    ]
                ];
            }
            // Simple type
            else {
                $properties[$propName] = ['type' => $this->mapSimpleTypeToJsonSchema($propType)];
            }
        }
    }
    
    /**
     * Helper method to map basic PHP types to JSON Schema types
     */
    private function mapSimpleTypeToJsonSchema(string $type): string
    {
        return match (strtolower($type)) {
            'string' => 'string',
            'int', 'integer' => 'integer',
            'bool', 'boolean' => 'boolean',
            'float', 'double', 'number' => 'number',
            'array' => 'array',
            'object', 'stdclass' => 'object',
            default => in_array(strtolower($type), ['datetime', 'datetimeinterface']) ? 'string' : 'object',
        };
    }
}
