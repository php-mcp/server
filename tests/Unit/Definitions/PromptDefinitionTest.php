<?php

namespace PhpMcp\Server\Tests\Unit\Definitions;

use Mockery;
use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Definitions\PromptArgumentDefinition;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\AllElementsStub;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\ToolOnlyStub;
use ReflectionMethod;
use ReflectionParameter;

// --- Constructor Validation Tests ---

test('constructor validates prompt name pattern', function (string $promptName, bool $shouldFail) {
    $action = fn () => new PromptDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        promptName: $promptName,
        description: 'Desc',
        arguments: []
    );

    if ($shouldFail) {
        expect($action)->toThrow(\InvalidArgumentException::class, "Prompt name '{$promptName}' is invalid");
    } else {
        expect($action)->not->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['valid-prompt_name1', false],
    ['validPrompt', false],
    ['invalid name', true], // Space
    ['invalid!@#', true],   // Special chars
    ['', true],              // Empty
]);

// --- fromReflection Tests ---

beforeEach(function () {
    $this->docBlockParser = Mockery::mock(DocBlockParser::class);
});

test('fromReflection creates definition with explicit name and description', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpPrompt(name: 'explicit-prompt-name', description: 'Explicit Description');
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Mock parser
    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    // Mocks for argument processing (needed for fromReflection to run)
    $this->docBlockParser->shouldReceive('getParamTags')->once()->with(null)->andReturn([]);

    // Act
    $definition = PromptDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $this->docBlockParser
    );

    // Assert
    expect($definition->getName())->toBe('explicit-prompt-name');
    expect($definition->getDescription())->toBe('Explicit Description');
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    // Assert arguments based on reflection (templateMethod has 1 param: $id)
    expect($definition->getArguments())->toBeArray()->toHaveCount(1);
    expect($definition->getArguments()[0]->getName())->toBe('id');
});

test('fromReflection uses method name and docblock summary as defaults', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(AllElementsStub::class, 'templateMethod');
    $attribute = new McpPrompt();
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Read the actual summary from the stub file
    $stubContent = file_get_contents(__DIR__.'/../../Mocks/DiscoveryStubs/AllElementsStub.php');
    preg_match('/\/\*\*(.*?)\*\/\s+public function templateMethod/s', $stubContent, $matches);
    $actualDocComment = isset($matches[1]) ? trim(preg_replace('/^\s*\*\s?/?m', '', $matches[1])) : '';
    $expectedSummary = explode("\n", $actualDocComment)[0] ?? null;

    // Mock parser
    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn($expectedSummary);
    $this->docBlockParser->shouldReceive('getParamTags')->once()->with(null)->andReturn([]);

    // Act
    $definition = PromptDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $this->docBlockParser
    );

    // Assert
    expect($definition->getName())->toBe('templateMethod'); // Default to method name
    expect($definition->getDescription())->toBe($expectedSummary); // Default to summary
    expect($definition->getClassName())->toBe(AllElementsStub::class);
    expect($definition->getMethodName())->toBe('templateMethod');
    expect($definition->getArguments())->toBeArray()->toHaveCount(1); // templateMethod has 1 param
});

test('fromReflection handles missing docblock summary', function () {
    // Arrange
    $reflectionMethod = new ReflectionMethod(ToolOnlyStub::class, 'tool1');
    $attribute = new McpPrompt();
    $docComment = $reflectionMethod->getDocComment() ?: null;

    // Mock parser
    $this->docBlockParser->shouldReceive('parseDocBlock')->once()->with($docComment)->andReturn(null);
    $this->docBlockParser->shouldReceive('getSummary')->once()->with(null)->andReturn(null);
    $this->docBlockParser->shouldReceive('getParamTags')->once()->with(null)->andReturn([]);

    // Act
    $definition = PromptDefinition::fromReflection(
        $reflectionMethod,
        $attribute->name,
        $attribute->description,
        $this->docBlockParser
    );

    // Assert
    expect($definition->getName())->toBe('tool1');
    expect($definition->getDescription())->toBeNull();
    expect($definition->getClassName())->toBe(ToolOnlyStub::class);
    expect($definition->getMethodName())->toBe('tool1');
    expect($definition->getArguments())->toBeArray()->toBeEmpty(); // tool1 has no params
});

// --- Serialization Tests ---

test('can be serialized and unserialized correctly via toArray/fromArray', function () {
    // Arrange
    // Use a real argument definition based on the stub method
    $reflectionParam = new ReflectionParameter([AllElementsStub::class, 'templateMethod'], 'id');
    $arg1 = PromptArgumentDefinition::fromReflection($reflectionParam, null); // Assume null tag for simplicity

    $original = new PromptDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        promptName: 'serial-prompt',
        description: 'Testing serialization',
        arguments: [$arg1]
    );

    // Act
    $mcpArray = $original->toArray();
    $internalArray = [
        'className' => $original->getClassName(),
        'methodName' => $original->getMethodName(),
        'promptName' => $original->getName(),
        'description' => $original->getDescription(),
        'arguments' => $mcpArray['arguments'], // Use the toArray version of arguments
    ];

    $reconstructed = PromptDefinition::fromArray($internalArray);

    // Assert
    expect($reconstructed)->toEqual($original); // Should work with real argument object
    expect($reconstructed->getArguments()[0]->getName())->toBe('id');
});

test('toArray produces correct MCP format', function () {
    // Arrange
    // Create real arguments based on stub
    $reflectionParam = new ReflectionParameter([AllElementsStub::class, 'templateMethod'], 'id');
    $arg1 = PromptArgumentDefinition::fromReflection($reflectionParam, null);

    $definition = new PromptDefinition(
        className: AllElementsStub::class,
        methodName: 'templateMethod',
        promptName: 'mcp-prompt',
        description: 'MCP Description',
        arguments: [$arg1]
    );
    $definitionMinimal = new PromptDefinition(
        className: ToolOnlyStub::class,
        methodName: 'tool1',
        promptName: 'mcp-minimal',
        description: null,
        arguments: []
    );

    // Act
    $array = $definition->toArray();
    $arrayMinimal = $definitionMinimal->toArray();

    // Assert
    expect($array)->toBe([
        'name' => 'mcp-prompt',
        'description' => 'MCP Description',
        'arguments' => [
            ['name' => 'id', 'required' => true],
        ],
    ]);
    expect($arrayMinimal)->toBe([
        'name' => 'mcp-minimal',
    ]);
    expect($arrayMinimal)->not->toHaveKeys(['description', 'arguments']);
});
