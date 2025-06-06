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
        inputSchema: ['type' => 'object']
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
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->toolName)->toBe('explicit-tool-name');
    expect($definition->description)->toBe('Explicit Description');
    expect($definition->className)->toBe(AllElementsStub::class);
    expect($definition->methodName)->toBe('templateMethod');
    expect($definition->inputSchema)->toBe($expectedSchema);
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
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->toolName)->toBe('templateMethod'); // Default to method name
    expect($definition->description)->toBe($expectedSummary); // Default to actual summary
    expect($definition->className)->toBe(AllElementsStub::class);
    expect($definition->methodName)->toBe('templateMethod');
    expect($definition->inputSchema)->toBe($expectedSchema);
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
        $this->docBlockParser,
        $this->schemaGenerator
    );

    expect($definition->toolName)->toBe('ToolOnlyStub');
    expect($definition->className)->toBe(ToolOnlyStub::class);
    expect($definition->methodName)->toBe('__invoke');
    expect($definition->inputSchema)->toBe(['type' => 'object']);
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
        $this->docBlockParser,
        $this->schemaGenerator
    );

    // Assert
    expect($definition->toolName)->toBe('tool1');
    expect($definition->description)->toBeNull(); // No description available
    expect($definition->className)->toBe(ToolOnlyStub::class);
    expect($definition->methodName)->toBe('tool1');
    expect($definition->inputSchema)->toBe($expectedSchema);
});

// --- Serialization Tests ---

test('can be serialized and unserialized correctly via toArray/fromArray', function () {
    // Arrange
    $original = new ToolDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        toolName: 'serial-tool',
        description: 'Testing serialization',
        inputSchema: ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]]
    );

    // Act
    $mcpArray = $original->toArray();
    $internalArray = [
        'className' => $original->className,
        'methodName' => $original->methodName,
        'toolName' => $original->toolName,
        'description' => $original->description,
        'inputSchema' => $original->inputSchema,
    ];
    $reconstructed = ToolDefinition::fromArray($internalArray);

    // Assert
    expect($reconstructed)->toEqual($original);
    expect($reconstructed->inputSchema)->toBe($original->inputSchema);
});

test('toArray produces correct MCP format', function () {
    // Arrange
    $definition = new ToolDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        toolName: 'mcp-tool',
        description: 'MCP Description',
        inputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]
    );
    $definitionNoDesc = new ToolDefinition(
        className: ToolOnlyStub::class,
        methodName: 'tool1',
        toolName: 'mcp-tool-no-desc',
        description: null,
        inputSchema: ['type' => 'object']
    );

    // Act
    $array = $definition->toArray();
    $arrayNoDesc = $definitionNoDesc->toArray();

    // Assert
    expect($array)->toBe([
        'name' => 'mcp-tool',
        'description' => 'MCP Description',
        'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
    ]);
    expect($arrayNoDesc)->toBe([
        'name' => 'mcp-tool-no-desc',
        'inputSchema' => ['type' => 'object'],
    ]);
    expect($arrayNoDesc)->not->toHaveKey('description');
});
