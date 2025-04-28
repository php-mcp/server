<?php

namespace PhpMcp\Server\Support;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
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

                // Add format for specific string types (basic inference)
                if (isset($paramSchema['type'])) {
                    $schemaType = is_array($paramSchema['type']) ? (in_array('string', $paramSchema['type']) ? 'string' : null) : $paramSchema['type'];
                    if ($schemaType === 'string') {
                        if (stripos($name, 'email') !== false || stripos($typeString, 'email') !== false) {
                            $paramSchema['format'] = 'email';
                        } elseif (stripos($name, 'date') !== false || stripos($typeString, 'date') !== false) {
                            $paramSchema['format'] = 'date-time'; // Or 'date' depending on convention
                        } elseif (stripos($name, 'uri') !== false || stripos($name, 'url') !== false || stripos($typeString, 'uri') !== false || stripos($typeString, 'url') !== false) {
                            $paramSchema['format'] = 'uri';
                        }
                        // Add more format detections if needed
                    }
                }

                // Handle array items type if possible
                if (isset($paramSchema['type'])) {
                    $schemaType = is_array($paramSchema['type']) ? (in_array('array', $paramSchema['type']) ? 'array' : null) : $paramSchema['type'];
                    if (in_array('array', $this->mapPhpTypeToJsonSchemaType($typeString))) {
                        $itemsType = $this->inferArrayItemsType($typeString);
                        if ($itemsType !== 'any') {
                            if (is_string($itemsType)) {
                                $paramSchema['items'] = ['type' => $itemsType];
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

            $schema['properties'][$name] = $paramSchema;

            if ($required) {
                $schema['required'][] = $name;
            }
        }

        if (empty($schema['properties'])) {
            // Keep properties object even if empty, per spec
            $schema['properties'] = new stdClass();
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
     *     is_variadic: bool
     * }>
     */
    private function parseParametersInfo(ReflectionMethod $method, ?DocBlock $docBlock): array
    {
        $paramTags = $this->docBlockParser->getParamTags($docBlock);
        $parametersInfo = [];

        foreach ($method->getParameters() as $rp) {
            $paramName = $rp->getName();
            $paramTag = $paramTags['$'.$paramName] ?? null;

            $reflectionType = $rp->getType();
            $typeString = $this->getParameterTypeString($rp, $paramTag);
            $description = $this->docBlockParser->getParamDescription($paramTag);
            $hasDefault = $rp->isDefaultValueAvailable();
            $defaultValue = $hasDefault ? $rp->getDefaultValue() : null;
            $isVariadic = $rp->isVariadic();

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

        // PRIORITY 1: Check for array syntax first (T[] or generics)
        if (str_contains($normalizedType, '[]') || preg_match('/^(array|list|iterable|collection)</i', $normalizedType)) {
            return ['array'];
        }

        // PRIORITY 2: Handle unions (recursive)
        if (str_contains($normalizedType, '|')) {
            $types = explode('|', $normalizedType);
            $jsonTypes = [];
            foreach ($types as $type) {
                $mapped = $this->mapPhpTypeToJsonSchemaType(trim($type));
                $jsonTypes = array_merge($jsonTypes, $mapped);
            }

            return array_values(array_unique($jsonTypes));
        }

        // PRIORITY 3: Handle simple built-in types
        return match ($normalizedType) {
            'string', 'scalar' => ['string'],
            '?string' => ['null', 'string'],
            'int', 'integer' => ['integer'],
            '?int' => ['null', 'integer'],
            'float', 'double', 'number' => ['number'],
            '?float', '?double' => ['null', 'number'],
            'bool', 'boolean' => ['boolean'],
            '?bool' => ['null', 'boolean'],
            'array' => ['array'], // Catch native 'array' hint if not caught by generics/[]
            '?array' => ['null', 'array'],
            'object' => ['object'], // Catch native 'object' hint
            '?object' => ['null', 'object'],
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
        $itemType = null;

        // Match T[] or ?T[] syntax, capturing T
        if (preg_match('/^(\\??)(string|int|integer|bool|boolean|float|double|number)\\s*\\[\\]$/i', $normalizedType, $matches)) {
            $itemType = strtolower($matches[2]);

            return match ($itemType) {
                'string' => 'string',
                'int', 'integer' => 'integer',
                'bool', 'boolean' => 'boolean',
                'float', 'double', 'number' => 'number',
                default => 'any',
            };
        }

        // No support for array<K,V>, list<V>, or arrays of objects/enums yet
        return 'any';
    }
}
