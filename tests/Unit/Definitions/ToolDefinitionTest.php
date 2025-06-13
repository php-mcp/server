<?php

namespace PhpMcp\Server\Tests\Unit\Definitions;

use Mockery;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Support\SchemaGenerator;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\AllElementsStub;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\ToolOnlyStub;
use ReflectionMethod;

test('constructor validates tool name pattern', function (string $toolName, bool $shouldFail) {
    $action = fn() => new ToolDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        toolName: $toolName,
        description: 'Desc',
        inputSchema: ['type' => 'object'],
        annotations: ['title' => 'Test Tool', 'readOnlyHint' => true]
    );

    if ($shouldFail) {
        expect($action)->toThrow(\InvalidArgumentException::class, "Tool name '{$toolName}' is invalid");
    } else {
        expect($action)->not->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['valid-tool_name1', false],
    ['validTool', false],
    ['invalid name', true], // Space
    ['invalid!@#', true],   // Special chars
    ['', true],              // Empty
]);

// --- fromReflection Tests ---

beforeEach(function () {
    $this->docBlockParser = Mockery::mock(DocBlockParser::class);
    $this->schemaGenerator = Mockery::mock(SchemaGenerator::class);
});

test('fromReflection creates definition with explicit name and description', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpTool(name: 'explicit-tool-name', description: 'Explicit Description');
    $expectedSchema = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];
    $docComment = $reflectionMethod->getDocComment() ?: null;

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->schemaGenerator->shouldReceive('fromMethodParameters')->once()->with($reflectionMethod)->andReturn($expectedSchema);

    // Act
    $definition = ToolDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->annotations,
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->getName())->toBe('explicit-tool-name');
    expect($definition->getDescription())->toBe('Explicit Description');
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    expect($definition->getInputSchema())->toBe($expectedSchema);
    expect($definition->getAnnotations())->toBe([]);
});

test('fromReflection creates definition with explicit annotations', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $annotations = ['title' => 'Custom Tool', 'destructiveHint' => true, 'category' => 'admin'];

    $attribute = new McpTool(name: 'explicit-tool-name', description: 'Explicit Description', annotations: $annotations);
    $expectedSchema = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];
    $docComment = $reflectionMethod->getDocComment() ?: null;

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->schemaGenerator->shouldReceive('fromMethodParameters')->once()->with($reflectionMethod)->andReturn($expectedSchema);

    // Act
    $definition = ToolDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->annotations,
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->getName())->toBe('explicit-tool-name');
    expect($definition->getDescription())->toBe('Explicit Description');
    expect($definition->getAnnotations())->toBe($annotations);
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    expect($definition->getInputSchema())->toBe($expectedSchema);
});

test('fromReflection uses method name and docblock summary as defaults', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpTool();

    $expectedSchema = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Read the actual summary from the stub file to make the test robust
    $stubContent = file_get_contents(__DIR__ . '/../../Mocks/DiscoveryStubs/AllElementsStub.php');
    preg_match('/\/\*\*(.*?)\*\/\s+public function templateMethod/s', $stubContent, $matches);
    $actualDocComment = isset($matches[1]) ? trim(preg_replace('/^\s*\*\s?/?m', '', $matches[1])) : '';
    $expectedSummary = explode("\n", $actualDocComment)[0] ?? null; // First line is summary

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn($expectedSummary);
    $this->schemaGenerator->shouldReceive('fromMethodParameters')->once()->with($reflectionMethod)->andReturn($expectedSchema);

    // Act
    $definition = ToolDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->annotations,
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->getName())->toBe('templateMethod'); // Default to method name
    expect($definition->getDescription())->toBe($expectedSummary); // Default to actual summary
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    expect($definition->getInputSchema())->toBe($expectedSchema);
    expect($definition->getAnnotations())->toBe([]);
});

test('fromReflection uses class short name as default tool name for invokable classes', function () {
    $reflectionMethod = new ReflectionMethod(ToolOnlyStub::class, '__invoke');

    $docComment = $reflectionMethod->getDocComment() ?: null;

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->schemaGenerator->shouldReceive('fromMethodParameters')->once()->with($reflectionMethod)->andReturn(['type' => 'object']);

    $definition = ToolDefinition::fromReflection(
        $reflectionMethod,
        null,
        "Some description",
        null,
        $this->docBlockParser,
        $this->schemaGenerator
    );

    expect($definition->getName())->toBe('ToolOnlyStub');
    expect($definition->getClassName())->toBe(ToolOnlyStub::class);
    expect($definition->getMethodName())->toBe('__invoke');
    expect($definition->getInputSchema())->toBe(['type' => 'object']);
    expect($definition->getAnnotations())->toBe([]);
});

test('fromReflection handles missing docblock summary', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(ToolOnlyStub::class, 'tool1');
    $attribute = new McpTool();
    $expectedSchema = ['type' => 'object', 'properties' => []]; // tool1 has no params
    $docComment = $reflectionMethod->getDocComment() ?: null; // Will be null/empty

    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn(null);
    $this->schemaGenerator->shouldReceive('fromMethodParameters')->once()->with($reflectionMethod)->andReturn($expectedSchema);

    // Act
    $definition = ToolDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $attribute->annotations,
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->getName())->toBe('tool1');
    expect($definition->getDescription())->toBeNull(); // No description available
    expect($definition->getClassName())->toBe(ToolOnlyStub::class);
    expect($definition->getMethodName())->toBe('tool1');
    expect($definition->getInputSchema())->toBe($expectedSchema);
    expect($definition->getAnnotations())->toBe([]);
});

// --- Serialization Tests ---

test('can be serialized and unserialized correctly via toArray/fromArray', function () {
    // Arrange
    $original = new ToolDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        toolName: 'serial-tool',
        description: 'Testing serialization',
        inputSchema: ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
        annotations: ['title' => 'Serialization Tool', 'category' => 'test']
    );

    // Act
    $mcpArray = $original->toArray();
    $internalArray = [
        'className' => $original->getClassName(),
        'methodName' => $original->getMethodName(),
        'toolName' => $original->getName(),
        'description' => $original->getDescription(),
        'inputSchema' => $original->getInputSchema(),
        'annotations' => $original->getAnnotations(),
    ];
    $reconstructed = ToolDefinition::fromArray($internalArray);

    // Assert
    expect($reconstructed)->toEqual($original);
    expect($reconstructed->getInputSchema())->toBe($original->getInputSchema());
    expect($reconstructed->getAnnotations())->toBe($original->getAnnotations());
});

test('toArray produces correct MCP format', function () {
    // Arrange
    $definition = new ToolDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        toolName: 'mcp-tool',
        description: 'MCP Description',
        inputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        annotations: ['title' => 'MCP Tool', 'readOnlyHint' => true]
    );
    $definitionNoDesc = new ToolDefinition(
        className: ToolOnlyStub::class,
        methodName: 'tool1',
        toolName: 'mcp-tool-no-desc',
        description: null,
        inputSchema: ['type' => 'object'],
        annotations: []
    );

    // Act
    $array = $definition->toArray();
    $arrayNoDesc = $definitionNoDesc->toArray();

    // Assert
    expect($array)->toBe([
        'name' => 'mcp-tool',
        'description' => 'MCP Description',
        'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'annotations' => ['title' => 'MCP Tool', 'readOnlyHint' => true],
    ]);
    expect($arrayNoDesc)->toBe([
        'name' => 'mcp-tool-no-desc',
        'inputSchema' => ['type' => 'object'],
    ]);
    expect($arrayNoDesc)->not->toHaveKey('description');
    expect($arrayNoDesc)->not->toHaveKey('annotations');
});

