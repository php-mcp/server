<?php

uses(\PhpMcp\Server\Tests\TestCase::class);

use PhpMcp\Server\Utils\DocBlockParser;
use PhpMcp\Server\Utils\SchemaGenerator;
use PhpMcp\Server\Tests\Fixtures\Utils\SchemaGeneratorFixture;

beforeEach(function () {
    $docBlockParser = new DocBlockParser();
    $this->schemaGenerator = new SchemaGenerator($docBlockParser);
});

it('generates an empty properties object for a method with no parameters', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'noParams');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema)->toEqual([
        'type' => 'object',
        'properties' => new stdClass()
    ]);
    expect($schema)->not->toHaveKey('required');
});

it('infers basic types from PHP type hints when no DocBlocks or Schema attributes are present', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'typeHintsOnly');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['name'])->toEqual(['type' => 'string']);
    expect($schema['properties']['age'])->toEqual(['type' => 'integer']);
    expect($schema['properties']['active'])->toEqual(['type' => 'boolean']);
    expect($schema['properties']['tags'])->toEqual(['type' => 'array']);
    expect($schema['properties']['config'])->toEqual(['type' => ['null', 'object'], 'default' => null]);

    expect($schema['required'])->toEqualCanonicalizing(['name', 'age', 'active', 'tags']);
});

it('infers types and descriptions from DocBlock @param tags when no PHP type hints are present', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'docBlockOnly');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['username'])->toEqual(['type' => 'string', 'description' => 'The username']);
    expect($schema['properties']['count'])->toEqual(['type' => 'integer', 'description' => 'Number of items']);
    expect($schema['properties']['enabled'])->toEqual(['type' => 'boolean', 'description' => 'Whether enabled']);
    expect($schema['properties']['data'])->toEqual(['type' => 'array', 'description' => 'Some data']);

    expect($schema['required'])->toEqualCanonicalizing(['username', 'count', 'enabled', 'data']);
});

it('uses PHP type hints for type and DocBlock @param tags for descriptions when both are present', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'typeHintsWithDocBlock');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['email'])->toEqual(['type' => 'string', 'description' => 'User email address']);
    expect($schema['properties']['score'])->toEqual(['type' => 'integer', 'description' => 'User score']);
    expect($schema['properties']['verified'])->toEqual(['type' => 'boolean', 'description' => 'Whether user is verified']);

    expect($schema['required'])->toEqualCanonicalizing(['email', 'score', 'verified']);
});

it('uses the complete schema definition provided by a method-level #[Schema(definition: ...)] attribute', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'methodLevelCompleteDefinition');
    $schema = $this->schemaGenerator->generate($method);

    // Should return the complete definition as-is
    expect($schema)->toEqual([
        'type' => 'object',
        'description' => 'Creates a custom filter with complete definition',
        'properties' => [
            'field' => ['type' => 'string', 'enum' => ['name', 'date', 'status']],
            'operator' => ['type' => 'string', 'enum' => ['eq', 'gt', 'lt', 'contains']],
            'value' => ['description' => 'Value to filter by, type depends on field and operator']
        ],
        'required' => ['field', 'operator', 'value'],
        'if' => [
            'properties' => ['field' => ['const' => 'date']]
        ],
        'then' => [
            'properties' => ['value' => ['type' => 'string', 'format' => 'date']]
        ]
    ]);
});

it('generates schema from a method-level #[Schema] attribute defining properties for each parameter', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'methodLevelWithProperties');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['description'])->toBe("Creates a new user with detailed information.");
    expect($schema['properties']['username'])->toEqual(['type' => 'string', 'minLength' => 3, 'pattern' => '^[a-zA-Z0-9_]+$']);
    expect($schema['properties']['email'])->toEqual(['type' => 'string', 'format' => 'email']);
    expect($schema['properties']['age'])->toEqual(['type' => 'integer', 'minimum' => 18, 'description' => 'Age in years.']);
    expect($schema['properties']['isActive'])->toEqual(['type' => 'boolean', 'default' => true]);

    expect($schema['required'])->toEqualCanonicalizing(['age', 'username', 'email']);
});

it('generates schema for a single array argument defined by a method-level #[Schema] attribute', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'methodLevelArrayArgument');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['profiles'])->toEqual([
        'type' => 'array',
        'description' => 'An array of user profiles to update.',
        'minItems' => 1,
        'items' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'data' => ['type' => 'object', 'additionalProperties' => true]
            ],
            'required' => ['id', 'data']
        ]
    ]);

    expect($schema['required'])->toEqual(['profiles']);
});

it('generates schema from individual parameter-level #[Schema] attributes', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'parameterLevelOnly');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['recipientId'])->toEqual(['description' => "Recipient ID", 'pattern' => "^user_", 'type' => 'string']);
    expect($schema['properties']['messageBody'])->toEqual(['maxLength' => 1024, 'type' => 'string']);
    expect($schema['properties']['priority'])->toEqual(['type' => 'integer', 'enum' => [1, 2, 5], 'default' => 1]);
    expect($schema['properties']['notificationConfig'])->toEqual([
        'type' => 'object',
        'properties' => [
            'type' => ['type' => 'string', 'enum' => ['sms', 'email', 'push']],
            'deviceToken' => ['type' => 'string', 'description' => 'Required if type is push']
        ],
        'required' => ['type'],
        'default' => null
    ]);

    expect($schema['required'])->toEqualCanonicalizing(['recipientId', 'messageBody']);
});

it('applies string constraints from parameter-level #[Schema] attributes', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'parameterStringConstraints');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['email'])->toEqual(['format' => 'email', 'type' => 'string']);
    expect($schema['properties']['password'])->toEqual(['minLength' => 8, 'pattern' => '^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$', 'type' => 'string']);
    expect($schema['properties']['regularString'])->toEqual(['type' => 'string']);

    expect($schema['required'])->toEqualCanonicalizing(['email', 'password', 'regularString']);
});

it('applies numeric constraints from parameter-level #[Schema] attributes', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'parameterNumericConstraints');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['age'])->toEqual(['minimum' => 18, 'maximum' => 120, 'type' => 'integer']);
    expect($schema['properties']['rating'])->toEqual(['minimum' => 0, 'maximum' => 5, 'exclusiveMaximum' => true, 'type' => 'number']);
    expect($schema['properties']['count'])->toEqual(['multipleOf' => 10, 'type' => 'integer']);

    expect($schema['required'])->toEqualCanonicalizing(['age', 'rating', 'count']);
});

it('applies array constraints (minItems, uniqueItems, items schema) from parameter-level #[Schema]', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'parameterArrayConstraints');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['tags'])->toEqual(['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'uniqueItems' => true]);
    expect($schema['properties']['scores'])->toEqual(['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'minItems' => 1, 'maxItems' => 5]);

    expect($schema['required'])->toEqualCanonicalizing(['tags', 'scores']);
});

it('merges method-level and parameter-level #[Schema] attributes, with parameter-level taking precedence', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'methodAndParameterLevel');
    $schema = $this->schemaGenerator->generate($method);

    // Method level defines base properties
    expect($schema['properties']['settingKey'])->toEqual(['type' => 'string', 'description' => 'The key of the setting.']);

    // Parameter level Schema overrides method level for newValue
    expect($schema['properties']['newValue'])->toEqual(['description' => "The specific new boolean value.", 'type' => 'boolean']);

    expect($schema['required'])->toEqualCanonicalizing(['settingKey', 'newValue']);
});

it('combines PHP type hints, DocBlock descriptions, and parameter-level #[Schema] constraints', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'typeHintDocBlockAndParameterSchema');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['username'])->toEqual(['minLength' => 3, 'pattern' => '^[a-zA-Z0-9_]+$', 'type' => 'string', 'description' => "The user's name"]);
    expect($schema['properties']['priority'])->toEqual(['minimum' => 1, 'maximum' => 10, 'type' => 'integer', 'description' => 'Task priority level']);

    expect($schema['required'])->toEqualCanonicalizing(['username', 'priority']);
});

it('generates correct schema for backed and unit enum parameters, inferring from type hints', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'enumParameters');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['stringEnum'])->toEqual(['type' => 'string', 'description' => 'Backed string enum', 'enum' => ['A', 'B']]);
    expect($schema['properties']['intEnum'])->toEqual(['type' => 'integer', 'description' => 'Backed int enum', 'enum' => [1, 2]]);
    expect($schema['properties']['unitEnum'])->toEqual(['type' => 'string', 'description' => 'Unit enum', 'enum' => ['Yes', 'No']]);
    expect($schema['properties']['nullableEnum'])->toEqual(['type' => ['null', 'string'], 'enum' => ['A', 'B'], 'default' => null]);
    expect($schema['properties']['enumWithDefault'])->toEqual(['type' => 'integer', 'enum' => [1, 2], 'default' => 1]);

    expect($schema['required'])->toEqualCanonicalizing(['stringEnum', 'intEnum', 'unitEnum']);
});

it('correctly generates schemas for various array type declarations (generic, typed, shape)', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'arrayTypeScenarios');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['genericArray'])->toEqual(['type' => 'array', 'description' => 'Generic array']);
    expect($schema['properties']['stringArray'])->toEqual(['type' => 'array', 'description' => 'Array of strings', 'items' => ['type' => 'string']]);
    expect($schema['properties']['intArray'])->toEqual(['type' => 'array', 'description' => 'Array of integers', 'items' => ['type' => 'integer']]);
    expect($schema['properties']['mixedMap'])->toEqual(['type' => 'array', 'description' => 'Mixed array map']);

    // Object-like arrays should be converted to object type
    expect($schema['properties']['objectLikeArray'])->toHaveKey('type');
    expect($schema['properties']['objectLikeArray']['type'])->toBe('object');
    expect($schema['properties']['objectLikeArray'])->toHaveKey('properties');
    expect($schema['properties']['objectLikeArray']['properties'])->toHaveKeys(['name', 'age']);

    expect($schema['required'])->toEqualCanonicalizing(['genericArray', 'stringArray', 'intArray', 'mixedMap', 'objectLikeArray', 'nestedObjectArray']);
});

it('handles nullable type hints and optional parameters with default values correctly', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'nullableAndOptional');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['nullableString'])->toEqual(['type' => ['null', 'string'], 'description' => 'Nullable string']);
    expect($schema['properties']['nullableInt'])->toEqual(['type' => ['null', 'integer'], 'description' => 'Nullable integer', 'default' => null]);
    expect($schema['properties']['optionalString'])->toEqual(['type' => 'string', 'default' => 'default']);
    expect($schema['properties']['optionalBool'])->toEqual(['type' => 'boolean', 'default' => true]);
    expect($schema['properties']['optionalArray'])->toEqual(['type' => 'array', 'default' => []]);

    expect($schema['required'])->toEqualCanonicalizing(['nullableString']);
});

it('generates schema for PHP union types, sorting types alphabetically', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'unionTypes');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['stringOrInt'])->toEqual(['type' => ['integer', 'string'], 'description' => 'String or integer']);
    expect($schema['properties']['multiUnion'])->toEqual(['type' => ['null', 'boolean', 'string'], 'description' => 'Bool, string or null']);

    expect($schema['required'])->toEqualCanonicalizing(['stringOrInt', 'multiUnion']);
});

it('represents variadic string parameters as an array of strings', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'variadicStrings');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['items'])->toEqual(['type' => 'array', 'description' => 'Variadic strings', 'items' => ['type' => 'string']]);
    expect($schema)->not->toHaveKey('required');
    // Variadic is optional
});

it('applies item constraints from parameter-level #[Schema] to variadic parameters', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'variadicWithConstraints');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['numbers'])->toEqual(['items' => ['type' => 'integer', 'minimum' => 0], 'type' => 'array', 'description' => 'Variadic integers']);
    expect($schema)->not->toHaveKey('required');
});

it('handles mixed type hints, omitting explicit type in schema and using defaults', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'mixedTypes');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['anyValue'])->toEqual(['description' => 'Any value']);
    expect($schema['properties']['optionalAny'])->toEqual(['description' => 'Optional any value', 'default' => 'default']);

    expect($schema['required'])->toEqualCanonicalizing(['anyValue']);
});

it('generates schema for complex nested object and array structures defined in parameter-level #[Schema]', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'complexNestedSchema');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['order'])->toEqual([
        'type' => 'object',
        'properties' => [
            'customer' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => '^CUS-[0-9]{6}$'],
                    'name' => ['type' => 'string', 'minLength' => 2],
                    'email' => ['type' => 'string', 'format' => 'email']
                ],
                'required' => ['id', 'name']
            ],
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'pattern' => '^PRD-[0-9]{4}$'],
                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                        'price' => ['type' => 'number', 'minimum' => 0]
                    ],
                    'required' => ['product_id', 'quantity', 'price']
                ]
            ],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => true
            ]
        ],
        'required' => ['customer', 'items']
    ]);

    expect($schema['required'])->toEqual(['order']);
});

it('demonstrates type precedence: parameter #[Schema] overrides DocBlock, which overrides PHP type hint', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'typePrecedenceTest');
    $schema = $this->schemaGenerator->generate($method);

    // DocBlock type (integer) should override PHP type (string)
    expect($schema['properties']['numericString'])->toEqual(['type' => 'integer', 'description' => 'DocBlock says integer despite string type hint']);

    // Schema constraints should be applied with PHP type
    expect($schema['properties']['stringWithConstraints'])->toEqual(['format' => 'email', 'minLength' => 5, 'type' => 'string', 'description' => 'String with Schema constraints']);

    // Schema should override DocBlock array item type
    expect($schema['properties']['arrayWithItems'])->toEqual(['items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'type' => 'array', 'description' => 'Array with Schema item overrides']);

    expect($schema['required'])->toEqualCanonicalizing(['numericString', 'stringWithConstraints', 'arrayWithItems']);
});

it('generates an empty properties object for a method with no parameters even if a method-level #[Schema] is present', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'noParamsWithSchema');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['description'])->toBe("Gets server status. Takes no arguments.");
    expect($schema['properties'])->toBeInstanceOf(stdClass::class);
    expect($schema)->not->toHaveKey('required');
});

it('infers parameter type as "any" (omits type) if only constraints are given in #[Schema] without type hint or DocBlock type', function () {
    $method = new ReflectionMethod(SchemaGeneratorFixture::class, 'parameterSchemaInferredType');
    $schema = $this->schemaGenerator->generate($method);

    expect($schema['properties']['inferredParam'])->toEqual(['description' => "Some parameter", 'minLength' => 3]);

    expect($schema['required'])->toEqual(['inferredParam']);
});
