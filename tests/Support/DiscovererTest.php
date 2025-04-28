<?php

namespace Tests\Discovery;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Support\AttributeFinder;
use PhpMcp\Server\Support\Discoverer;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Support\SchemaGenerator;
use Psr\Log\LoggerInterface;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    setupTempDir();
    $this->registry = Mockery::mock(Registry::class);
    /** @var LoggerInterface $logger */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    $attributeFinder = new AttributeFinder();
    $docBlockParser = new DocBlockParser();
    $schemaGenerator = new SchemaGenerator($docBlockParser, $attributeFinder);

    $this->discoverer = new Discoverer(
        $this->registry,
        $this->logger,
        $attributeFinder,
        $docBlockParser,
        $schemaGenerator
    );
});

afterEach(function () {
    cleanupTempDir();
});

test('discovers all element types in a single file', function () {
    // Arrange
    $filePath = createDiscoveryTestFile('AllElementsStub');

    // Assert registry interactions
    $this->registry->shouldReceive('registerTool')->once()->with(Mockery::on(function ($arg) {
        return $arg instanceof ToolDefinition && $arg->getName() === 'discovered-tool';
    }));
    $this->registry->shouldReceive('registerResource')->once()->with(Mockery::on(function ($arg) {
        return $arg instanceof ResourceDefinition && $arg->getUri() === 'discovered://resource';
    }));
    $this->registry->shouldReceive('registerPrompt')->once()->with(Mockery::on(function ($arg) {
        return $arg instanceof PromptDefinition && $arg->getName() === 'discovered-prompt';
    }));
    $this->registry->shouldReceive('registerResourceTemplate')->once()->with(Mockery::on(function ($arg) {
        return $arg instanceof ResourceTemplateDefinition && $arg->getUriTemplate() === 'discovered://template/{id}';
    }));

    $this->logger->shouldNotReceive('error')->with(Mockery::any(), Mockery::on(fn ($ctx) => isset($ctx['file']) && $ctx['file'] === $filePath));

    // Act
    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['.']);
});

test('discovers elements across multiple files', function () {
    // Arrange
    $file1Path = createDiscoveryTestFile('ToolOnlyStub');
    $file2Path = createDiscoveryTestFile('ResourceOnlyStub');

    // Assert registry interactions
    $this->registry->shouldReceive('registerTool')->once()->with(Mockery::on(fn ($arg) => $arg->getName() === 'tool-from-file1'));
    $this->registry->shouldNotReceive('registerResource');
    $this->registry->shouldNotReceive('registerPrompt');
    $this->registry->shouldNotReceive('registerResourceTemplate');

    // Ensure no errors during processing of these files
    $this->logger->shouldNotReceive('error')->with(Mockery::any(), Mockery::on(fn ($ctx) => isset($ctx['file']) && ($ctx['file'] === $file1Path || $ctx['file'] === $file2Path)));

    // Act
    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['.']);
});

test('handles directory with no MCP elements', function () {
    // Arrange
    createDiscoveryTestFile('PlainPhpClass');

    // Assert registry interactions
    $this->registry->shouldNotReceive('registerTool');
    $this->registry->shouldNotReceive('registerResource');
    $this->registry->shouldNotReceive('registerPrompt');
    $this->registry->shouldNotReceive('registerResourceTemplate');

    // Act
    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['.']);
});

test('handles non-existent directory gracefully', function () {
    // Arrange
    $nonExistentDir = TEST_DISCOVERY_DIR.'/nonexistent';

    // Assert registry interactions
    $this->registry->shouldNotReceive('registerTool');
    $this->registry->shouldNotReceive('registerResource');
    $this->registry->shouldNotReceive('registerPrompt');
    $this->registry->shouldNotReceive('registerResourceTemplate');

    // Assert logging
    $this->logger->shouldReceive('warning')->with('No valid discovery directories found.', Mockery::any())->twice();

    // Act
    $this->discoverer->discover($nonExistentDir, ['.']); // Base path doesn't exist
    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['nonexistent_subdir']);
});

test('skips non-instantiable classes and non-public/static/constructor methods', function (string $stubName, int $expectedRegistrations) {
    // Arrange
    $filePath = createDiscoveryTestFile($stubName);

    if ($expectedRegistrations === 0) {
        $this->registry->shouldNotReceive('registerTool');
        $this->registry->shouldNotReceive('registerResource');
        $this->registry->shouldNotReceive('registerPrompt');
        $this->registry->shouldNotReceive('registerResourceTemplate');
    } else {
        // Example if one tool is expected (adjust if other types can be expected)
        $this->registry->shouldReceive('registerTool')->times($expectedRegistrations);
        $this->registry->shouldNotReceive('registerResource');
        $this->registry->shouldNotReceive('registerPrompt');
        $this->registry->shouldNotReceive('registerResourceTemplate');
    }

    // Ensure no processing errors for this file
    $this->logger->shouldNotReceive('error')->with(Mockery::any(), Mockery::on(fn ($ctx) => isset($ctx['file']) && $ctx['file'] === $filePath));

    // Act
    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['.']);

})->with([
    'Abstract class' => ['AbstractStub', 0],
    'Interface' => ['InterfaceStub', 0],
    'Trait' => ['TraitStub', 0],
    'Enum' => ['EnumStub', 0],
    'Static method' => ['StaticMethodStub', 0],
    'Protected method' => ['ProtectedMethodStub', 0],
    'Private method' => ['PrivateMethodStub', 0],
    'Constructor' => ['ConstructorStub', 0],
    'Inherited method' => ['ChildInheriting', 0], // Child has no *declared* methods with attributes
    'Class using Trait' => ['ClassUsingTrait', 1], // Expect the trait method to be found
    // Need to also test scanning the parent/trait files directly if needed
]);

test('handles definition creation error and continues', function () {
    // Arrange
    $filePath = createDiscoveryTestFile('MixedValidityStub');

    // Assert registry interactions
    $this->registry->shouldReceive('registerTool')
        ->with(Mockery::on(fn ($arg) => $arg instanceof ToolDefinition && $arg->getName() === 'valid-tool'))
        ->once();
    $this->registry->shouldReceive('registerTool')
        ->with(Mockery::on(fn ($arg) => $arg instanceof ToolDefinition && $arg->getName() === 'another-valid-tool'))
        ->once();
    $this->registry->shouldNotReceive('registerResource');

    // Ensure no *other* unexpected errors related to this class/methods
    $this->logger->shouldNotReceive('error')
        ->with(Mockery::any(), Mockery::on(fn ($ctx) => isset($ctx['file']) && $ctx['file'] === $filePath));

    // Act
    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['.']);
});

test('handles file read error gracefully', function () {
    // Arrange
    $invalidFile = TEST_DISCOVERY_DIR.'/invalid.php';
    touch($invalidFile); // Create the file
    chmod($invalidFile, 0000); // Make it unreadable

    // Assert registry interactions
    $this->registry->shouldNotReceive('registerTool');
    $this->registry->shouldNotReceive('registerResource');
    $this->registry->shouldNotReceive('registerPrompt');
    $this->registry->shouldNotReceive('registerResourceTemplate');

    $this->discoverer->discover(TEST_DISCOVERY_DIR, ['.']);

    // Cleanup permissions
    chmod($invalidFile, 0644);
});
