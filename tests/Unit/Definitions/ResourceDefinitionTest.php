<?php

namespace PhpMcp\Server\Tests\Unit\Definitions;

use Mockery;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\AllElementsStub;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\ResourceOnlyStub;
use ReflectionMethod;

// --- Constructor Validation Tests ---

test('constructor validates resource name pattern', function (string $resourceName, bool $shouldFail) {
    $action = fn () => new ResourceDefinition(
        className: AllElementsStub::class,
        methodName: 'resourceMethod',
        uri: 'file:///valid/uri',
        name: $resourceName,
        description: 'Desc',
        mimeType: 'text/plain',
        size: 100,
    );

    if ($shouldFail) {
        expect($action)->toThrow(\InvalidArgumentException::class, "Resource name '{$resourceName}' is invalid");
    } else {
        expect($action)->not->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['valid-res_name1', false],
    ['validRes', false],
    ['invalid name', true], // Space
    ['invalid!@#', true],   // Special chars
    ['', true],              // Empty
]);

test('constructor validates URI pattern', function (string $uri, bool $shouldFail) {
    $action = fn () => new ResourceDefinition(
        className: AllElementsStub::class,
        methodName: 'resourceMethod',
        uri: $uri,
        name: 'valid-name',
        description: 'Desc',
        mimeType: 'text/plain',
        size: 100,
    );

    if ($shouldFail) {
        expect($action)->toThrow(\InvalidArgumentException::class, "Resource URI '{$uri}' is invalid");
    } else {
        expect($action)->not->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['file:///valid/path', false],
    ['config://app/settings', false],
    ['custom+scheme://data?id=1', false],
    ['noscheme', true], // Missing ://
    ['invalid-scheme:/path', true], // Missing //
    ['file:/invalid//path', true], // Missing //
    ['http://', false], // Valid scheme, empty authority/path is allowed by regex
    ['http://host:port/path', false],
    [' ', true], // Empty/Whitespace
]);

// --- fromReflection Tests ---

beforeEach(function () {
    $this->docBlockParser = Mockery::mock(DocBlockParser::class);
});

test('fromReflection creates definition with explicit values from attribute', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'resourceMethod');
    $attribute = new McpResource(
        uri: 'test://explicit/uri',
        name: 'explicit-res-name',
        description: 'Explicit Description',
        mimeType: 'application/json',
        size: 1234,
    );
    $docComment = $reflectionMethod->getDocComment() ?: null;

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);

    // Act
    $definition = ResourceDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->uri,
        $attribute->mimeType,
        $attribute->size,
        $this->docBlockParser
    );

    // Assert
    expect($definition->uri)->toBe('test://explicit/uri');
    expect($definition->name)->toBe('explicit-res-name');
    expect($definition->description)->toBe('Explicit Description');
    expect($definition->className)->toBe(AllElementsStub::class);
    expect($definition->methodName)->toBe('resourceMethod');
    expect($definition->mimeType)->toBe('application/json');
    expect($definition->size)->toBe(1234);
});

test('fromReflection uses method name and docblock summary as defaults', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'resourceMethod');
    $attribute = new McpResource(uri: 'test://default/uri');
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Read the actual summary from the stub file
    $stubContent = file_get_contents(__DIR__ . '/../../Mocks/DiscoveryStubs/AllElementsStub.php');
    preg_match('/\/\*\*(.*?)\*\/\s+public function resourceMethod/s', $stubContent, $matches);
    $actualDocComment = isset($matches[1]) ? trim(preg_replace('/^\s*\*\s?/?m', '', $matches[1])) : '';
    $expectedSummary = explode("\n", $actualDocComment)[0] ?? null;

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn($expectedSummary);

    // Act
    $definition = ResourceDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->uri,
        $attribute->mimeType,
        $attribute->size,
        $this->docBlockParser
    );

    // Assert
    expect($definition->uri)->toBe('test://default/uri');
    expect($definition->name)->toBe('resourceMethod'); // Default to method name
    expect($definition->description)->toBe($expectedSummary); // Default to summary
    expect($definition->className)->toBe(AllElementsStub::class);
    expect($definition->methodName)->toBe('resourceMethod');
    expect($definition->mimeType)->toBeNull();
    expect($definition->size)->toBeNull();
});

test('fromReflection handles missing docblock summary', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(ResourceOnlyStub::class, 'resource2');
    $attribute = new McpResource(uri: 'test://no/desc');
    $docComment = $reflectionMethod->getDocComment() ?: null;

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn(null);

    // Act
    $definition = ResourceDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->uri,
        $attribute->mimeType,
        $attribute->size,
        $this->docBlockParser
    );

    // Assert
    expect($definition->name)->toBe('resource2');
    expect($definition->description)->toBeNull();
    expect($definition->className)->toBe(ResourceOnlyStub::class);
    expect($definition->methodName)->toBe('resource2');
});

// --- Serialization Tests ---

test('can be serialized and unserialized correctly via toArray/fromArray', function () {
    // Arrange
    $original = new ResourceDefinition(
        className: AllElementsStub::class,
        methodName: 'resourceMethod',
        uri: 'serial://test/resource',
        name: 'serial-res',
        description: 'Testing serialization',
        mimeType: 'image/jpeg',
        size: 9876,
    );

    // Act
    $mcpArray = $original->toArray();
    $internalArray = [
        'className' => $original->className,
        'methodName' => $original->methodName,
        'uri' => $original->uri,
        'name' => $original->name,
        'description' => $original->description,
        'mimeType' => $original->mimeType,
        'size' => $original->size,
    ];
    $reconstructed = ResourceDefinition::fromArray($internalArray);

    // Assert
    expect($reconstructed)->toEqual($original);
    expect($reconstructed->size)->toBe($original->size);
});

test('toArray produces correct MCP format', function () {
    // Arrange
    $definition = new ResourceDefinition(
        className: AllElementsStub::class,
        methodName: 'resourceMethod',
        uri: 'mcp://resource',
        name: 'mcp-res',
        description: 'MCP Description',
        mimeType: 'text/markdown',
        size: 555,
    );
    $definitionMinimal = new ResourceDefinition(
        className: ResourceOnlyStub::class,
        methodName: 'resource2',
        uri: 'mcp://minimal',
        name: 'mcp-minimal',
        description: null,
        mimeType: null,
        size: null,
    );

    // Act
    $array = $definition->toArray();
    $arrayMinimal = $definitionMinimal->toArray();

    // Assert
    expect($array)->toBe([
        'uri' => 'mcp://resource',
        'name' => 'mcp-res',
        'description' => 'MCP Description',
        'mimeType' => 'text/markdown',
        'size' => 555,
    ]);
    expect($arrayMinimal)->toBe([
        'uri' => 'mcp://minimal',
        'name' => 'mcp-minimal',
    ]);
    expect($arrayMinimal)->not->toHaveKeys(['description', 'mimeType', 'size']);
});
