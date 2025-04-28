<?php

namespace PhpMcp\Server\Tests\Support;

use Mockery;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactory;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Support\SchemaGenerator;
use PhpMcp\Server\Tests\Mocks\SupportStubs\SchemaGeneratorTestStub;
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

    expect($schema)->toEqual(['type' => 'object', 'properties' => new \stdClass()]);
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
    // Skip test if PHP version is less than 8.1
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        expect(true)->toBeTrue(); // Placeholder assertion

        return; // Skip test
    }

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

test('generates schema infers format from parameter name', function () {
    $method = new ReflectionMethod(SchemaGeneratorTestStub::class, 'formatParams');
    setupDocBlockExpectations($this->docBlockParserMock, $method);

    $schema = $this->schemaGenerator->fromMethodParameters($method);

    expect($schema['properties']['email'])->toEqual(['type' => 'string', 'description' => 'Email address', 'format' => 'email']);
    expect($schema['properties']['url'])->toEqual(['type' => 'string', 'description' => 'URL string', 'format' => 'uri']);
    expect($schema['properties']['dateTime'])->toEqual(['type' => 'string', 'description' => 'ISO Date time string', 'format' => 'date-time']);
    expect($schema['required'])->toEqualCanonicalizing(['email', 'url', 'dateTime']);
});
