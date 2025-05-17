<?php

namespace PhpMcp\Server\Tests\Unit\Support;

use Mockery;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactory;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Support\SchemaGenerator;
use PhpMcp\Server\Tests\Mocks\SupportStubs\SchemaGeneratorTestStub;
use PhpMcp\Server\Tests\Mocks\SupportStubs\SchemaAttributeTestStub;
use PhpMcp\Server\Tests\Mocks\SupportStubs\DocBlockArrayTestStub;
use ReflectionMethod;

// --- Setup ---
beforeEach(function () {
    $this->docBlockParserMock = Mockery::mock(DocBlockParser::class);
    $this->schemaGenerator = new SchemaGenerator($this->docBlockParserMock);
});

// --- Helper Function for Mocking ---
function setupDocBlockExpectations(Mockery\MockInterface $parserMock, ReflectionMethod $method): void
{
    $docComment = $method->getDocComment() ?: '';
    $realDocBlock = $docComment ? DocBlockFactory::createInstance()->create($docComment) : null;
    $parserMock->shouldReceive('parseDocBlock')->once()->with($docComment ?: null)->andReturn($realDocBlock);

    $realParamTags = [];
    if ($realDocBlock) {
        foreach ($realDocBlock->getTagsByName('param') as $tag) {
            if ($tag instanceof Param && $tag->getVariableName()) {
                $realParamTags['$'.$tag->getVariableName()] = $tag;
            }
        }
    }
    $parserMock->shouldReceive('getParamTags')->once()->with($realDocBlock)->andReturn($realParamTags);

    // Set expectations for each parameter based on whether it has a real tag
    foreach ($method->getParameters() as $rp) {
        $paramName = $rp->getName();
        $tagName = '$'.$paramName;
        $tag = $realParamTags[$tagName] ?? null;

        // Mock the calls the generator will make for this specific parameter
        $expectedType = $tag ? (string) $tag->getType() : null;
        $expectedDesc = $tag ? ($tag->getDescription() ? $tag->getDescription()->render() : null) : null;

        $parserMock->shouldReceive('getParamTypeString')->with($tag)->andReturn($expectedType);
        $parserMock->shouldReceive('getParamDescription')->with($tag)->andReturn($expectedDesc);
    }
}

// --- Test Cases ---

test('generates empty schema for method with no parameters', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'noParams');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema)->toEqual(['type' => 'object', 'properties' => new \stdClass]);
    expect($schema)->not->toHaveKey('required');
});

test('generates schema for required simple types', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'simpleRequired');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['p1'])->toEqual(['type' => 'string', 'description' => 'String param']);
    expect($schema['properties']['p2'])->toEqual(['type' => 'integer', 'description' => 'Int param']);
    expect($schema['properties']['p3'])->toEqual(['type' => 'boolean', 'description' => 'Bool param']);
    expect($schema['properties']['p4'])->toEqual(['type' => 'number', 'description' => 'Float param']);
    expect($schema['properties']['p5'])->toEqual(['type' => 'array', 'description' => 'Array param']);
    expect($schema['properties']['p6'])->toEqual(['type' => 'object', 'description' => 'Object param']);
    expect($schema['required'])->toEqualCanonicalizing(['p1', 'p2', 'p3', 'p4', 'p5', 'p6']);
});

test('generates schema for optional simple types with defaults', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'simpleOptionalDefaults');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['p1'])->toEqual(['type' => 'string', 'description' => 'String param', 'default' => 'default']);
    expect($schema['properties']['p2'])->toEqual(['type' => 'integer', 'description' => 'Int param', 'default' => 123]);
    expect($schema['properties']['p3'])->toEqual(['type' => 'boolean', 'description' => 'Bool param', 'default' => true]);
    expect($schema['properties']['p4'])->toEqual(['type' => 'number', 'description' => 'Float param', 'default' => 1.23]);
    expect($schema['properties']['p5'])->toEqual(['type' => 'array', 'description' => 'Array param', 'default' => ['a', 'b']]);
    expect($schema['properties']['p6'])->toEqual(['type' => ['null', 'object'], 'description' => 'Object param', 'default' => null]); // Nullable type from reflection
    expect($schema)->not->toHaveKey('required');
});

test('generates schema for nullable types without explicit default', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'nullableWithoutDefault');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['p1'])->toEqual(['type' => ['null', 'string'], 'description' => 'Nullable string']);
    expect($schema['properties']['p2'])->toEqual(['type' => ['integer', 'null'], 'description' => 'Nullable int shorthand']);
    expect($schema['properties']['p3'])->toEqual(['type' => ['boolean', 'null'], 'description' => 'Nullable bool']);

    // Required because they don't have a default value
    expect($schema)->toHaveKey('required');
});

test('generates schema for nullable types with explicit null default', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'nullableWithNullDefault');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // These are optional because they have a default value (null), so not required.
    expect($schema['properties']['p1'])->toEqual(['type' => ['null', 'string'], 'description' => 'Nullable string with default', 'default' => null]);
    expect($schema['properties']['p2'])->toEqual(['type' => ['integer', 'null'], 'description' => 'Nullable int shorthand with default', 'default' => null]);
    expect($schema)->not->toHaveKey('required');
});

test('generates schema for union types', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'unionTypes');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['p1'])->toEqual(['type' => ['integer', 'string'], 'description' => 'String or Int']); // Sorted types
    expect($schema['properties']['p2'])->toEqual(['type' => ['boolean', 'null', 'string'], 'description' => 'Bool, String or Null']); // Sorted types
    expect($schema['required'])->toEqualCanonicalizing(['p1', 'p2']); // Neither has default
});

test('generates schema for array types', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'arrayTypes');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['p1'])->toEqual(['type' => 'array', 'description' => 'Generic array']); // No items info
    expect($schema['properties']['p2'])->toEqual(['type' => 'array', 'description' => 'Array of strings (docblock)', 'items' => ['type' => 'string']]);
    expect($schema['properties']['p3'])->toEqual(['type' => 'array', 'description' => 'Array of integers (docblock)', 'items' => ['type' => 'integer']]);
    // expect($schema['properties']['p4'])->toEqual(['type' => 'array', 'description' => 'Generic array map (docblock)', 'items' => ['type' => 'string']]); // Infers value type
    // expect($schema['properties']['p5'])->toEqual(['type' => 'array', 'description' => 'Array of enums (docblock)', 'items' => ['type' => 'string']]); // Enum maps to string backing type
    expect($schema['properties']['p6'])->toEqual(['type' => 'array', 'description' => 'Array of nullable booleans (docblock)', 'items' => ['type' => 'boolean']]); // Item type bool, nullability on outer?
    expect($schema['required'])->toEqualCanonicalizing(['p1', 'p2', 'p3', 'p4', 'p5', 'p6']);
});

test('generates schema for enum types', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'enumTypes');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Backed String Enum
    expect($schema['properties']['p1'])->toEqual(['type' => 'string', 'description' => 'Backed string enum', 'enum' => ['A', 'B']]);
    // Backed Int Enum
    expect($schema['properties']['p2'])->toEqual(['type' => 'integer', 'description' => 'Backed int enum', 'enum' => [1, 2]]);
    // Unit Enum
    expect($schema['properties']['p3'])->toEqual(['type' => 'string', 'description' => 'Unit enum', 'enum' => ['Yes', 'No']]);
    // Nullable Backed String Enum (No default)
    expect($schema['properties']['p4'])->toEqual(['type' => ['string', 'null'], 'description' => 'Nullable backed string enum', 'enum' => ['A', 'B']]);
    // Optional Backed Int Enum (With default)
    expect($schema['properties']['p5'])->toEqual(['type' => 'integer', 'description' => 'Optional backed int enum', 'enum' => [1, 2], 'default' => 1]);
    // Optional Unit Enum (With null default)
    expect($schema['properties']['p6'])->toEqual(['type' => ['string', 'null'], 'description' => 'Optional unit enum with null default', 'enum' => ['Yes', 'No'], 'default' => null]);

    // Check required fields (p4, p5, p6 are optional)
    expect($schema['required'])->toEqualCanonicalizing(['p1', 'p2', 'p3', 'p4']);
})->skip(version_compare(PHP_VERSION, '8.1', '<'), 'Enums require PHP 8.1+');

test('generates schema for variadic parameters', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'variadicParam');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Variadic params are represented as arrays
    expect($schema['properties']['items'])->toEqual(['type' => 'array', 'description' => 'Variadic strings', 'items' => ['type' => 'string']]);
    expect($schema)->not->toHaveKey('required'); // Variadic params are inherently optional
});

test('generates schema for mixed type', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'mixedType');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Mixed type results in no 'type' constraint in JSON Schema
    expect($schema['properties']['p1'])->toEqual(['description' => 'Mixed type']);
    expect($schema['properties']['p2'])->toEqual(['description' => 'Optional mixed type', 'default' => 'hello']);
    expect($schema['required'])->toEqualCanonicalizing(['p1']); // p2 has default
});

test('generates schema using docblock type when no php type hint', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'docBlockOnly');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['p1'])->toEqual(['type' => 'string', 'description' => 'Only docblock type']); // Type from docblock
    expect($schema['properties']['p2'])->toEqual(['description' => 'Only docblock description']); // No type info
    expect($schema['required'])->toEqualCanonicalizing(['p1', 'p2']);
});

test('generates schema using docblock type overriding php type hint', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'docBlockOverrides');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Docblock type (@param string) overrides PHP type hint (int)
    expect($schema['properties']['p1'])->toEqual(['type' => 'string', 'description' => 'Docblock overrides int']);
    expect($schema['required'])->toEqualCanonicalizing(['p1']);
});

test('generates schema with string format constraints from Schema attribute', function () {
    $method = new ReflectionMethod(SchemaAttributeTestStub::class, 'stringConstraints');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['email'])->toHaveKey('format')
        ->and($schema['properties']['email']['format'])->toBe('email');
    
    expect($schema['properties']['password'])->toHaveKey('minLength')
        ->and($schema['properties']['password']['minLength'])->toBe(8);
    expect($schema['properties']['password'])->toHaveKey('pattern')
        ->and($schema['properties']['password']['pattern'])->toBe('^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$');
    
    // Regular parameter should not have format constraints
    expect($schema['properties']['code'])->not->toHaveKey('format');
    expect($schema['properties']['code'])->not->toHaveKey('minLength');
});

test('generates schema with numeric constraints from Schema attribute', function () {
    $method = new ReflectionMethod(SchemaAttributeTestStub::class, 'numericConstraints');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['age'])->toHaveKey('minimum')
        ->and($schema['properties']['age']['minimum'])->toBe(18);
    expect($schema['properties']['age'])->toHaveKey('maximum')
        ->and($schema['properties']['age']['maximum'])->toBe(120);

    expect($schema['properties']['rating'])->toHaveKey('minimum')
        ->and($schema['properties']['rating']['minimum'])->toBe(0);
    expect($schema['properties']['rating'])->toHaveKey('maximum')
        ->and($schema['properties']['rating']['maximum'])->toBe(5);
    expect($schema['properties']['rating'])->toHaveKey('exclusiveMaximum')
        ->and($schema['properties']['rating']['exclusiveMaximum'])->toBeTrue();

    expect($schema['properties']['count'])->toHaveKey('multipleOf')
        ->and($schema['properties']['count']['multipleOf'])->toBe(10);
});

test('generates schema with array constraints from Schema attribute', function () {
    $method = new ReflectionMethod(SchemaAttributeTestStub::class, 'arrayConstraints');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['tags'])->toHaveKey('uniqueItems')
        ->and($schema['properties']['tags']['uniqueItems'])->toBeTrue();
    expect($schema['properties']['tags'])->toHaveKey('minItems')
        ->and($schema['properties']['tags']['minItems'])->toBe(1);

    expect($schema['properties']['scores'])->toHaveKey('minItems')
        ->and($schema['properties']['scores']['minItems'])->toBe(1);
    expect($schema['properties']['scores'])->toHaveKey('maxItems')
        ->and($schema['properties']['scores']['maxItems'])->toBe(5);
    expect($schema['properties']['scores'])->toHaveKey('items')
        ->and($schema['properties']['scores']['items'])->toHaveKey('minimum')
        ->and($schema['properties']['scores']['items']['minimum'])->toBe(0);
    expect($schema['properties']['scores']['items'])->toHaveKey('maximum')
        ->and($schema['properties']['scores']['items']['maximum'])->toBe(100);

    // Regular array should not have constraints
    expect($schema['properties']['mixed'])->not->toHaveKey('minItems');
    expect($schema['properties']['mixed'])->not->toHaveKey('uniqueItems');
});

test('generates schema with object constraints from Schema attribute', function () {
    $method = new ReflectionMethod(SchemaAttributeTestStub::class, 'objectConstraints');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Check properties
    expect($schema['properties']['user'])->toHaveKey('properties');
    $properties = $schema['properties']['user']['properties'];

    expect($properties)->toHaveKeys(['name', 'email', 'age']);
    expect($properties['name'])->toHaveKey('minLength')
        ->and($properties['name']['minLength'])->toBe(2);
    expect($properties['email'])->toHaveKey('format')
        ->and($properties['email']['format'])->toBe('email');
    expect($properties['age'])->toHaveKey('minimum')
        ->and($properties['age']['minimum'])->toBe(18);

    // Check required
    expect($schema['properties']['user'])->toHaveKey('required')
        ->and($schema['properties']['user']['required'])->toContain('name')
        ->and($schema['properties']['user']['required'])->toContain('email');

    // Check additionalProperties
    expect($schema['properties']['config'])->toHaveKey('additionalProperties')
        ->and($schema['properties']['config']['additionalProperties'])->toBeTrue();
});

test('generates schema with nested constraints from Schema attribute', function () {
    $method = new ReflectionMethod(SchemaAttributeTestStub::class, 'nestedConstraints');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Check top level properties exist
    expect($schema['properties']['order'])->toHaveKey('properties');
    expect($schema['properties']['order']['properties'])->toHaveKeys(['customer', 'items']);
    
    // Check customer properties
    $customer = $schema['properties']['order']['properties']['customer'];
    expect($customer)->toHaveKey('properties');
    expect($customer['properties'])->toHaveKeys(['id', 'name']);
    expect($customer['properties']['id'])->toHaveKey('pattern');
    expect($customer['required'])->toContain('id');
    
    // Check items properties
    $items = $schema['properties']['order']['properties']['items'];
    expect($items)->toHaveKey('minItems')
        ->and($items['minItems'])->toBe(1);
    expect($items)->toHaveKey('items');
    
    // Check items schema
    $itemsSchema = $items['items'];
    expect($itemsSchema)->toHaveKey('properties');
    expect($itemsSchema['properties'])->toHaveKeys(['product_id', 'quantity']);
    expect($itemsSchema['required'])->toContain('product_id')
        ->and($itemsSchema['required'])->toContain('quantity');
    expect($itemsSchema['properties']['product_id'])->toHaveKey('pattern');
    expect($itemsSchema['properties']['quantity'])->toHaveKey('minimum')
        ->and($itemsSchema['properties']['quantity']['minimum'])->toBe(1);
});

test('respects precedence order between PHP type, DocBlock, and Schema attributes', function () {
    $method = new ReflectionMethod(SchemaAttributeTestStub::class, 'typePrecedenceTest');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Test Case 1: DocBlock type (integer) should override PHP type (string)
    // but keep string characteristics (not have integer constraints)
    expect($schema['properties']['numericString'])->toHaveKey('type')
        ->and($schema['properties']['numericString']['type'])->toBe('integer')
        ->and($schema['properties']['numericString'])->not->toHaveKey('format');  // No string format since type is now integer

    // Test Case 2: Schema format should be applied even when type is from PHP/DocBlock
    expect($schema['properties']['stringWithFormat'])->toHaveKey('type')
        ->and($schema['properties']['stringWithFormat']['type'])->toBe('string')
        ->and($schema['properties']['stringWithFormat'])->toHaveKey('format')
        ->and($schema['properties']['stringWithFormat']['format'])->toBe('email');

    // Test Case 3: Schema items constraints should override DocBlock array<string> hint
    expect($schema['properties']['arrayWithItems'])->toHaveKey('type')
        ->and($schema['properties']['arrayWithItems']['type'])->toBe('array')
        ->and($schema['properties']['arrayWithItems'])->toHaveKey('items')
        ->and($schema['properties']['arrayWithItems']['items'])->toHaveKey('minimum')
        ->and($schema['properties']['arrayWithItems']['items']['minimum'])->toBe(1)
        ->and($schema['properties']['arrayWithItems']['items'])->toHaveKey('maximum')
        ->and($schema['properties']['arrayWithItems']['items']['maximum'])->toBe(100);
});

test('parses simple array[] syntax correctly', function () {
    $method = new ReflectionMethod(DocBlockArrayTestStub::class, 'simpleArraySyntax');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Check each parameter type is correctly inferred
    expect($schema['properties']['strings'])->toHaveKey('items')
        ->and($schema['properties']['strings']['items']['type'])->toBe('string');
        
    expect($schema['properties']['integers'])->toHaveKey('items')
        ->and($schema['properties']['integers']['items']['type'])->toBe('integer');
        
    expect($schema['properties']['booleans'])->toHaveKey('items')
        ->and($schema['properties']['booleans']['items']['type'])->toBe('boolean');
        
    expect($schema['properties']['floats'])->toHaveKey('items')
        ->and($schema['properties']['floats']['items']['type'])->toBe('number');
        
    expect($schema['properties']['objects'])->toHaveKey('items')
        ->and($schema['properties']['objects']['items']['type'])->toBe('object');
        
    expect($schema['properties']['dateTimeInstances'])->toHaveKey('items')
        ->and($schema['properties']['dateTimeInstances']['items']['type'])->toBe('object');
});

test('parses array<T> generic syntax correctly', function () {
    $method = new ReflectionMethod(DocBlockArrayTestStub::class, 'genericArraySyntax');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Check each parameter type is correctly inferred
    expect($schema['properties']['strings'])->toHaveKey('items')
        ->and($schema['properties']['strings']['items']['type'])->toBe('string');
        
    expect($schema['properties']['integers'])->toHaveKey('items')
        ->and($schema['properties']['integers']['items']['type'])->toBe('integer');
        
    expect($schema['properties']['booleans'])->toHaveKey('items')
        ->and($schema['properties']['booleans']['items']['type'])->toBe('boolean');
        
    expect($schema['properties']['floats'])->toHaveKey('items')
        ->and($schema['properties']['floats']['items']['type'])->toBe('number');
        
    expect($schema['properties']['objects'])->toHaveKey('items')
        ->and($schema['properties']['objects']['items']['type'])->toBe('object');
        
    expect($schema['properties']['dateTimeInstances'])->toHaveKey('items')
        ->and($schema['properties']['dateTimeInstances']['items']['type'])->toBe('object');
});

test('parses nested array syntax correctly', function () {
    $method = new ReflectionMethod(DocBlockArrayTestStub::class, 'nestedArraySyntax');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Check for nested arrays with array<array<string>> syntax
    expect($schema['properties']['nestedStringArrays'])->toHaveKey('items')
        ->and($schema['properties']['nestedStringArrays']['items'])->toHaveKey('type')
        ->and($schema['properties']['nestedStringArrays']['items']['type'])->toBe('array')
        ->and($schema['properties']['nestedStringArrays']['items'])->toHaveKey('items')
        ->and($schema['properties']['nestedStringArrays']['items']['items']['type'])->toBe('string');
        
    // Check for nested arrays with array<array<int>> syntax
    expect($schema['properties']['nestedIntArrays'])->toHaveKey('items')
        ->and($schema['properties']['nestedIntArrays']['items'])->toHaveKey('type')
        ->and($schema['properties']['nestedIntArrays']['items']['type'])->toBe('array')
        ->and($schema['properties']['nestedIntArrays']['items'])->toHaveKey('items')
        ->and($schema['properties']['nestedIntArrays']['items']['items']['type'])->toBe('integer');
});

test('parses object-like array syntax correctly', function () {
    $method = new ReflectionMethod(DocBlockArrayTestStub::class, 'objectArraySyntax');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    // Simple object array
    expect($schema['properties']['person'])->toHaveKey('type')
        ->and($schema['properties']['person']['type'])->toBe('object');
    expect($schema['properties']['person'])->toHaveKey('properties')
        ->and($schema['properties']['person']['properties'])->toHaveKeys(['name', 'age']);
    expect($schema['properties']['person']['properties']['name']['type'])->toBe('string');
    expect($schema['properties']['person']['properties']['age']['type'])->toBe('integer');
    expect($schema['properties']['person'])->toHaveKey('required')
        ->and($schema['properties']['person']['required'])->toContain('name')
        ->and($schema['properties']['person']['required'])->toContain('age');
        
    // Object with nested array property
    expect($schema['properties']['article'])->toHaveKey('properties')
        ->and($schema['properties']['article']['properties'])->toHaveKey('tags')
        ->and($schema['properties']['article']['properties']['tags']['type'])->toBe('array')
        ->and($schema['properties']['article']['properties']['tags']['items']['type'])->toBe('string');
        
    // Complex object with nested object and array
    expect($schema['properties']['order'])->toHaveKey('properties')
        ->and($schema['properties']['order']['properties'])->toHaveKeys(['user', 'items']);
    expect($schema['properties']['order']['properties']['user']['type'])->toBe('object');
    expect($schema['properties']['order']['properties']['user']['properties'])->toHaveKeys(['id', 'name']);
    expect($schema['properties']['order']['properties']['items']['type'])->toBe('array')
        ->and($schema['properties']['order']['properties']['items']['items']['type'])->toBe('integer');
});
