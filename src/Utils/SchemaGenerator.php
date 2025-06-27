<?php

namespace PhpMcp\Server\Utils;

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
 * Generates JSON Schema for method parameters with intelligent Schema attribute handling.
 *
 * Priority system:
 * 1. Schema attributes (method-level and parameter-level)
 * 2. Reflection type information
 * 3. DocBlock type information
 */
class SchemaGenerator
{
    private DocBlockParser $docBlockParser;

    public function __construct(DocBlockParser $docBlockParser)
    {
        $this->docBlockParser = $docBlockParser;
    }

    /**
     * Generates a JSON Schema object (as a PHP array) for a method's or function's parameters.
     */
    public function generate(\ReflectionMethod|\ReflectionFunction $reflection): array
    {
        $methodSchema = $this->extractMethodLevelSchema($reflection);

        if ($methodSchema && isset($methodSchema['definition'])) {
            return $methodSchema['definition'];
        }

        $parametersInfo = $this->parseParametersInfo($reflection);

        return $this->buildSchemaFromParameters($parametersInfo, $methodSchema);
    }

    /**
     * Extracts method-level or function-level Schema attribute.
     */
    private function extractMethodLevelSchema(\ReflectionMethod|\ReflectionFunction $reflection): ?array
    {
        $schemaAttrs = $reflection->getAttributes(Schema::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (empty($schemaAttrs)) {
            return null;
        }

        $schemaAttr = $schemaAttrs[0]->newInstance();
        return $schemaAttr->toArray();
    }

    /**
     * Extracts parameter-level Schema attribute.
     */
    private function extractParameterLevelSchema(ReflectionParameter $parameter): array
    {
        $schemaAttrs = $parameter->getAttributes(Schema::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (empty($schemaAttrs)) {
            return [];
        }

        $schemaAttr = $schemaAttrs[0]->newInstance();
        return $schemaAttr->toArray();
    }

    /**
     * Builds the final schema from parameter information and method-level schema.
     *
     * @param array<int, array{
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
     *     parameter_schema: array<string, mixed>
     * }> $parametersInfo
     *
     * @param array<string, mixed>|null $methodSchema
     *
     * @return array<string, mixed>
     */
    private function buildSchemaFromParameters(array $parametersInfo, ?array $methodSchema): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        // Apply method-level schema as base
        if ($methodSchema) {
            $schema = array_merge($schema, $methodSchema);
            if (!isset($schema['type'])) {
                $schema['type'] = 'object';
            }
            if (!isset($schema['properties'])) {
                $schema['properties'] = [];
            }
            if (!isset($schema['required'])) {
                $schema['required'] = [];
            }
        }

        foreach ($parametersInfo as $paramInfo) {
            $paramName = $paramInfo['name'];

            $methodLevelParamSchema = $schema['properties'][$paramName] ?? null;

            $paramSchema = $this->buildParameterSchema($paramInfo, $methodLevelParamSchema);

            $schema['properties'][$paramName] = $paramSchema;

            if ($paramInfo['required'] && !in_array($paramName, $schema['required'])) {
                $schema['required'][] = $paramName;
            } elseif (!$paramInfo['required'] && ($key = array_search($paramName, $schema['required'])) !== false) {
                unset($schema['required'][$key]);
                $schema['required'] = array_values($schema['required']); // Re-index
            }
        }

        // Clean up empty properties
        if (empty($schema['properties'])) {
            $schema['properties'] = new stdClass();
        }
        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Builds the final schema for a single parameter by merging all three levels.
     *
     * @param array{
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
     *     parameter_schema: array<string, mixed>
     * } $paramInfo
     * @param array<string, mixed>|null $methodLevelParamSchema
     */
    private function buildParameterSchema(array $paramInfo, ?array $methodLevelParamSchema = null): array
    {
        if ($paramInfo['is_variadic']) {
            return $this->buildVariadicParameterSchema($paramInfo);
        }

        $inferredSchema = $this->buildInferredParameterSchema($paramInfo);

        // Method-level takes precedence over inferred schema
        $mergedSchema = $inferredSchema;
        if ($methodLevelParamSchema) {
            $mergedSchema = $this->mergeSchemas($inferredSchema, $methodLevelParamSchema);
        }

        // Parameter-level takes highest precedence
        $parameterLevelSchema = $paramInfo['parameter_schema'];
        if (!empty($parameterLevelSchema)) {
            $mergedSchema = $this->mergeSchemas($mergedSchema, $parameterLevelSchema);
        }

        return $mergedSchema;
    }

    /**
     * Merge two schemas where the dominant schema takes precedence over the recessive one.
     *
     * @param array $recessiveSchema The schema with lower precedence
     * @param array $dominantSchema The schema with higher precedence
     */
    private function mergeSchemas(array $recessiveSchema, array $dominantSchema): array
    {
        $mergedSchema = array_merge($recessiveSchema, $dominantSchema);

        return $mergedSchema;
    }

    /**
     * Builds parameter schema from inferred type and docblock information only.
     * Returns empty array for variadic parameters (handled separately).
     */
    private function buildInferredParameterSchema(array $paramInfo): array
    {
        $paramSchema = [];

        // Variadic parameters are handled separately
        if ($paramInfo['is_variadic']) {
            return [];
        }

        // Infer JSON Schema types
        $jsonTypes = $this->inferParameterTypes($paramInfo);

        if (count($jsonTypes) === 1) {
            $paramSchema['type'] = $jsonTypes[0];
        } elseif (count($jsonTypes) > 1) {
            $paramSchema['type'] = $jsonTypes;
        }

        // Add description from docblock
        if ($paramInfo['description']) {
            $paramSchema['description'] = $paramInfo['description'];
        }

        // Add default value only if parameter actually has a default
        if ($paramInfo['has_default']) {
            $paramSchema['default'] = $paramInfo['default_value'];
        }

        // Handle enums
        $paramSchema = $this->applyEnumConstraints($paramSchema, $paramInfo);

        // Handle array items
        $paramSchema = $this->applyArrayConstraints($paramSchema, $paramInfo);

        return $paramSchema;
    }

    /**
     * Builds schema for variadic parameters.
     */
    private function buildVariadicParameterSchema(array $paramInfo): array
    {
        $paramSchema = ['type' => 'array'];

        // Apply parameter-level Schema attributes first
        if (!empty($paramInfo['parameter_schema'])) {
            $paramSchema = array_merge($paramSchema, $paramInfo['parameter_schema']);
            // Ensure type is always array for variadic
            $paramSchema['type'] = 'array';
        }

        if ($paramInfo['description']) {
            $paramSchema['description'] = $paramInfo['description'];
        }

        // If no items specified by Schema attribute, infer from type
        if (!isset($paramSchema['items'])) {
            $itemJsonTypes = $this->mapPhpTypeToJsonSchemaType($paramInfo['type_string']);
            $nonNullItemTypes = array_filter($itemJsonTypes, fn($t) => $t !== 'null');

            if (count($nonNullItemTypes) === 1) {
                $paramSchema['items'] = ['type' => $nonNullItemTypes[0]];
            }
        }

        return $paramSchema;
    }

    /**
     * Infers JSON Schema types for a parameter.
     */
    private function inferParameterTypes(array $paramInfo): array
    {
        $jsonTypes = $this->mapPhpTypeToJsonSchemaType($paramInfo['type_string']);

        if ($paramInfo['allows_null'] && strtolower($paramInfo['type_string']) !== 'mixed' && !in_array('null', $jsonTypes)) {
            $jsonTypes[] = 'null';
        }

        if (count($jsonTypes) > 1) {
            // Sort but ensure null comes first for consistency
            $nullIndex = array_search('null', $jsonTypes);
            if ($nullIndex !== false) {
                unset($jsonTypes[$nullIndex]);
                sort($jsonTypes);
                array_unshift($jsonTypes, 'null');
            } else {
                sort($jsonTypes);
            }
        }

        return $jsonTypes;
    }

    /**
     * Applies enum constraints to parameter schema.
     */
    private function applyEnumConstraints(array $paramSchema, array $paramInfo): array
    {
        $reflectionType = $paramInfo['reflection_type_object'];

        if (!($reflectionType instanceof ReflectionNamedType) || $reflectionType->isBuiltin() || !enum_exists($reflectionType->getName())) {
            return $paramSchema;
        }

        $enumClass = $reflectionType->getName();
        $enumReflection = new ReflectionEnum($enumClass);
        $backingTypeReflection = $enumReflection->getBackingType();

        if ($enumReflection->isBacked() && $backingTypeReflection instanceof ReflectionNamedType) {
            $paramSchema['enum'] = array_column($enumClass::cases(), 'value');
            $jsonBackingType = match ($backingTypeReflection->getName()) {
                'int' => 'integer',
                'string' => 'string',
                default => null,
            };

            if ($jsonBackingType) {
                if (isset($paramSchema['type']) && is_array($paramSchema['type']) && in_array('null', $paramSchema['type'])) {
                    $paramSchema['type'] = ['null', $jsonBackingType];
                } else {
                    $paramSchema['type'] = $jsonBackingType;
                }
            }
        } else {
            // Non-backed enum - use names as enum values
            $paramSchema['enum'] = array_column($enumClass::cases(), 'name');
            if (isset($paramSchema['type']) && is_array($paramSchema['type']) && in_array('null', $paramSchema['type'])) {
                $paramSchema['type'] = ['null', 'string'];
            } else {
                $paramSchema['type'] = 'string';
            }
        }

        return $paramSchema;
    }

    /**
     * Applies array-specific constraints to parameter schema.
     */
    private function applyArrayConstraints(array $paramSchema, array $paramInfo): array
    {
        if (!isset($paramSchema['type'])) {
            return $paramSchema;
        }

        $typeString = $paramInfo['type_string'];
        $allowsNull = $paramInfo['allows_null'];

        // Handle object-like arrays using array{} syntax
        if (preg_match('/^array\s*{/i', $typeString)) {
            $objectSchema = $this->inferArrayItemsType($typeString);
            if (is_array($objectSchema) && isset($objectSchema['properties'])) {
                $paramSchema = array_merge($paramSchema, $objectSchema);
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
                    if (!isset($itemsType['type']) && isset($itemsType['properties'])) {
                        $itemsType = array_merge(['type' => 'object'], $itemsType);
                    }
                    $paramSchema['items'] = $itemsType;
                }
            }

            if ($allowsNull) {
                $paramSchema['type'] = ['array', 'null'];
                sort($paramSchema['type']);
            } else {
                $paramSchema['type'] = 'array';
            }
        }

        return $paramSchema;
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
     *     parameter_schema: array<string, mixed>
     * }>
     */
    private function parseParametersInfo(\ReflectionMethod|\ReflectionFunction $reflection): array
    {
        $docComment = $reflection->getDocComment() ?: null;
        $docBlock = $this->docBlockParser->parseDocBlock($docComment);
        $paramTags = $this->docBlockParser->getParamTags($docBlock);
        $parametersInfo = [];

        foreach ($reflection->getParameters() as $rp) {
            $paramName = $rp->getName();
            $paramTag = $paramTags['$' . $paramName] ?? null;

            $reflectionType = $rp->getType();
            $typeString = $this->getParameterTypeString($rp, $paramTag);
            $description = $this->docBlockParser->getParamDescription($paramTag);
            $hasDefault = $rp->isDefaultValueAvailable();
            $defaultValue = $hasDefault ? $rp->getDefaultValue() : null;
            $isVariadic = $rp->isVariadic();

            $parameterSchema = $this->extractParameterLevelSchema($rp);

            if ($defaultValue instanceof \BackedEnum) {
                $defaultValue = $defaultValue->value;
            }

            if ($defaultValue instanceof \UnitEnum) {
                $defaultValue = $defaultValue->name;
            }

            $allowsNull = false;
            if ($reflectionType && $reflectionType->allowsNull()) {
                $allowsNull = true;
            } elseif ($hasDefault && $defaultValue === null) {
                $allowsNull = true;
            } elseif (str_contains($typeString, 'null') || strtolower($typeString) === 'mixed') {
                $allowsNull = true;
            }

            $parametersInfo[] = [
                'name' => $paramName,
                'doc_block_tag' => $paramTag,
                'reflection_param' => $rp,
                'reflection_type_object' => $reflectionType,
                'type_string' => $typeString,
                'description' => $description,
                'required' => !$rp->isOptional(),
                'allows_null' => $allowsNull,
                'default_value' => $defaultValue,
                'has_default' => $hasDefault,
                'is_variadic' => $isVariadic,
                'parameter_schema' => $parameterSchema,
            ];
        }

        return $parametersInfo;
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
        if ($docBlockType !== null && !$isDocBlockTypeGeneric) {
            // Consider if DocBlock adds nullability missing from reflection
            if (stripos($docBlockType, 'null') !== false && $reflectionTypeString && stripos($reflectionTypeString, 'null') === false && !str_ends_with($reflectionTypeString, '|null')) {
                // If reflection didn't capture null, but docblock did, append |null (if not already mixed)
                if ($reflectionTypeString !== 'mixed') {
                    return $reflectionTypeString . '|null';
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
            return 'mixed';
        }

        $types = [];
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                $types[] = $this->getTypeStringFromReflection($innerType, $innerType->allowsNull());
            }
            if ($nativeAllowsNull) {
                $types = array_filter($types, fn($t) => strtolower($t) !== 'null');
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
            return 'mixed';
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

        if ($type instanceof ReflectionUnionType && !$nativeAllowsNull) {
            foreach ($type->getTypes() as $innerType) {
                if ($innerType instanceof ReflectionNamedType && strtolower($innerType->getName()) === 'null') {
                    $isNullable = true;
                    break;
                }
            }
        }

        if ($isNullable && $typeString !== 'mixed' && stripos($typeString, 'null') === false) {
            if (!str_ends_with($typeString, '|null') && !str_ends_with($typeString, '&null')) {
                $typeString .= '|null';
            }
        }

        // Remove leading backslash from class names, but handle built-ins like 'int' or unions like 'int|string'
        if (str_contains($typeString, '\\')) {
            $parts = preg_split('/([|&])/', $typeString, -1, PREG_SPLIT_DELIM_CAPTURE);
            $processedParts = array_map(fn($part) => str_starts_with($part, '\\') ? ltrim($part, '\\') : $part, $parts);
            $typeString = implode('', $processedParts);
        }

        return $typeString ?: 'mixed';
    }

    /**
     * Maps a PHP type string (potentially a union) to an array of JSON Schema type names.
     */
    private function mapPhpTypeToJsonSchemaType(string $phpTypeString): array
    {
        $normalizedType = strtolower(trim($phpTypeString));

        // PRIORITY 1: Check for array{} syntax which should be treated as object
        if (preg_match('/^array\s*{/i', $normalizedType)) {
            return ['object'];
        }

        // PRIORITY 2: Check for array syntax first (T[] or generics)
        if (
            str_contains($normalizedType, '[]') ||
            preg_match('/^(array|list|iterable|collection)</i', $normalizedType)
        ) {
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
            'array' => ['array'],
            '?array' => ['null', 'array'],
            'object', 'stdclass' => ['object'],
            '?object', '?stdclass' => ['null', 'object'],
            'null' => ['null'],
            'resource', 'callable' => ['object'],
            'mixed' => [],
            'void', 'never' => [],
            default => ['object'],
        };
    }

    /**
     * Infers the 'items' schema type for an array based on DocBlock type hints.
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
        if (
            preg_match('/^(\\??)array\s*<\s*array\s*<\s*([\w\\\\|]+)\s*>\s*>$/i', $normalizedType, $matches) ||
            preg_match('/^(\\??)([\w\\\\]+)\s*\[\]\[\]$/i', $normalizedType, $matches)
        ) {
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
        $buffer = '';

        for ($i = 0; $i < strlen($propertiesStr); $i++) {
            $char = $propertiesStr[$i];

            // Track nested braces
            if ($char === '{') {
                $depth++;
                $buffer .= $char;
            } elseif ($char === '}') {
                $depth--;
                $buffer .= $char;
            }
            // Property separator (comma)
            elseif ($char === ',' && $depth === 0) {
                // Process the completed property
                $this->parsePropertyDefinition(trim($buffer), $properties, $required);
                $buffer = '';
            } else {
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
            elseif (
                preg_match('/^array\s*<\s*([\w\\\\|]+)\s*>$/i', $propType, $arrayMatches) ||
                preg_match('/^([\w\\\\]+)\s*\[\]$/i', $propType, $arrayMatches)
            ) {
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
