<?php

namespace Tests\Definitions;

use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Support\DocBlockParser;
use Mockery;
use ReflectionMethod;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\AllElementsStub;

// --- Constructor Validation Tests ---

test('constructor validates template name pattern', function (string $templateName, bool $shouldFail) {
    $action = fn () => new ResourceTemplateDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        uriTemplate: 'user://{userId}/profile',
        name: $templateName,
        description: 'Desc',
        mimeType: 'application/json',
        annotations: []
    );

    if ($shouldFail) {
        expect($action)->toThrow(\InvalidArgumentException::class, "Resource name '{$templateName}' is invalid");
    } else {
        expect($action)->not->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['valid-tmpl_name1', false],
    ['validTmpl', false],
    ['invalid name', true], // Space
    ['invalid!@#', true],   // Special chars
    ['', true],              // Empty
]);

test('constructor validates URI template pattern', function (string $uriTemplate, bool $shouldFail) {
    $action = fn () => new ResourceTemplateDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        uriTemplate: $uriTemplate,
        name: 'valid-name',
        description: 'Desc',
        mimeType: 'application/json',
        annotations: []
    );

    if ($shouldFail) {
        expect($action)->toThrow(\InvalidArgumentException::class, "Resource URI template '{$uriTemplate}' is invalid");
    } else {
        expect($action)->not->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['file:///{path}', false],
    ['config://{setting}/value', false],
    ['user://{user_id}/data/{data_id}', false],
    ['noscheme/{id}', true], // Missing ://
    ['file://no_placeholder', true], // Missing {}
    ['file://{id', true], // Missing closing }
    ['file://id}', true], // Missing opening {
    ['http://{path}/sub', false],
    ['http://host:port/{path}', false],
    [' ', true], // Empty/Whitespace
]);

// --- fromReflection Tests ---

beforeEach(function () {
    $this->docBlockParser = Mockery::mock(DocBlockParser::class);
});

test('fromReflection creates definition with explicit values from attribute', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpResourceTemplate(
        uriTemplate: 'test://explicit/{id}/uri',
        name: 'explicit-tmpl-name',
        description: 'Explicit Description',
        mimeType: 'application/xml',
        annotations: ['priority' => 10]
    );
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Mock parser
    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);

    // Act
    $definition = ResourceTemplateDefinition::fromReflection($reflectionMethod, $attribute, $this->docBlockParser);

    // Assert
    expect($definition->getUriTemplate())->toBe('test://explicit/{id}/uri');
    expect($definition->getName())->toBe('explicit-tmpl-name');
    expect($definition->getDescription())->toBe('Explicit Description');
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    expect($definition->getMimeType())->toBe('application/xml');
    expect($definition->getAnnotations())->toBe(['priority' => 10]);
});

test('fromReflection uses method name and docblock summary as defaults', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpResourceTemplate(uriTemplate: 'test://default/{tmplId}');
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Read the actual summary from the stub file
    $stubContent = file_get_contents(__DIR__ . '/../Mocks/DiscoveryStubs/AllElementsStub.php');
    preg_match('/\/\*\*(.*?)\*\/\s+public function templateMethod/s', $stubContent, $matches);
    $actualDocComment = isset($matches[1]) ? trim(preg_replace('/^\s*\*\s?/?m', '', $matches[1])) : '';
    $expectedSummary = explode("\n", $actualDocComment)[0] ?? null;

    // Mock parser
    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn($expectedSummary);

    // Act
    $definition = ResourceTemplateDefinition::fromReflection($reflectionMethod, $attribute, $this->docBlockParser);

    // Assert
    expect($definition->getUriTemplate())->toBe('test://default/{tmplId}');
    expect($definition->getName())->toBe('templateMethod'); // Default to method name
    expect($definition->getDescription())->toBe($expectedSummary); // Default to summary
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    expect($definition->getMimeType())->toBeNull();
    expect($definition->getAnnotations())->toBe([]);
});

test('fromReflection handles missing docblock summary', function () {
    // Arrange
    // Use the same stub method, but mock the parser to return null for summary
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpResourceTemplate(uriTemplate: 'test://no/desc/{id}');
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Mock parser
    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn(null); // Mock no summary

    // Act
    $definition = ResourceTemplateDefinition::fromReflection($reflectionMethod, $attribute, $this->docBlockParser);

    // Assert
    expect($definition->getName())->toBe('templateMethod'); // Still defaults to method name
    expect($definition->getDescription())->toBeNull(); // No description available
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
});

// --- Serialization Tests ---

test('can be serialized and unserialized correctly via toArray/fromArray', function () {
    // Arrange
    $original = new ResourceTemplateDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        uriTemplate: 'serial://{type}/resource',
        name: 'serial-tmpl',
        description: 'Testing serialization',
        mimeType: 'text/csv',
        annotations: ['test' => true]
    );

    // Act
    $mcpArray = $original->toArray();
    $internalArray = [
        'className' => $original->getClassName(),
        'methodName' => $original->getMethodName(),
        'uriTemplate' => $original->getUriTemplate(),
        'name' => $original->getName(),
        'description' => $original->getDescription(),
        'mimeType' => $original->getMimeType(),
        'annotations' => $original->getAnnotations(),
    ];
    $reconstructed = ResourceTemplateDefinition::fromArray($internalArray);

    // Assert
    expect($reconstructed)->toEqual($original);
    expect($reconstructed->getAnnotations())->toBe($original->getAnnotations());
});

test('toArray produces correct MCP format', function () {
    // Arrange
    $definition = new ResourceTemplateDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        uriTemplate: 'mcp://{entity}/{id}',
        name: 'mcp-tmpl',
        description: 'MCP Description',
        mimeType: 'application/vnd.api+json',
        annotations: ['version' => '1.0']
    );
    $definitionMinimal = new ResourceTemplateDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        uriTemplate: 'mcp://minimal/{key}',
        name: 'mcp-minimal',
        description: null,
        mimeType: null,
        annotations: []
    );

    // Act
    $array = $definition->toArray();
    $arrayMinimal = $definitionMinimal->toArray();

    // Assert
    expect($array)->toBe([
        'uriTemplate' => 'mcp://{entity}/{id}',
        'name' => 'mcp-tmpl',
        'description' => 'MCP Description',
        'mimeType' => 'application/vnd.api+json',
        'annotations' => ['version' => '1.0']
    ]);
    expect($arrayMinimal)->toBe([
        'uriTemplate' => 'mcp://minimal/{key}',
        'name' => 'mcp-minimal'
    ]);
    expect($arrayMinimal)->not->toHaveKeys(['description', 'mimeType', 'annotations']);
});
