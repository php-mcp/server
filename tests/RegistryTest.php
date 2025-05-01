<?php

namespace Tests\Discovery; // Adjust namespace if needed based on your structure

use Mockery;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Registry;
use PhpMcp\Server\State\TransportState;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

const REGISTRY_CACHE_PREFIX = 'mcp_';
const EXPECTED_CACHE_KEY = REGISTRY_CACHE_PREFIX.'elements';

// Mocks and SUT instance
beforeEach(function () {
    $this->containerMock = Mockery::mock(ContainerInterface::class);
    $this->cache = Mockery::mock(CacheInterface::class);
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->config = Mockery::mock(ConfigurationRepositoryInterface::class);
    $this->transportState = Mockery::mock(TransportState::class)->shouldIgnoreMissing();

    $this->config->allows('get')->with('mcp.cache.prefix', Mockery::type('string'))->andReturn(REGISTRY_CACHE_PREFIX);
    $this->config->allows('get')->with('mcp.cache.ttl', Mockery::type('int'))->andReturn(3600);

    $this->cache->allows('get')->with(EXPECTED_CACHE_KEY)->andReturn(null)->byDefault();

    $this->containerMock->shouldReceive('get')->with(CacheInterface::class)->andReturn($this->cache);
    $this->containerMock->shouldReceive('get')->with(LoggerInterface::class)->andReturn($this->logger);
    $this->containerMock->shouldReceive('get')->with(ConfigurationRepositoryInterface::class)->andReturn($this->config);

    $this->registry = new Registry($this->containerMock, $this->transportState);
});

// --- Registration and Basic Retrieval Tests ---

test('can register and find a tool', function () {
    // Arrange
    $tool = createTestTool('my-tool');

    // Act
    $this->registry->registerTool($tool);
    $foundTool = $this->registry->findTool('my-tool');
    $notFoundTool = $this->registry->findTool('nonexistent-tool');

    // Assert
    expect($foundTool)->toBe($tool);
    expect($notFoundTool)->toBeNull();
});

test('can register and find a resource by URI', function () {
    // Arrange
    $resource = createTestResource('file:///exact/match.txt');

    // Act
    $this->registry->registerResource($resource);
    $foundResource = $this->registry->findResourceByUri('file:///exact/match.txt');
    $notFoundResource = $this->registry->findResourceByUri('file:///no-match.txt');

    // Assert
    expect($foundResource)->toBe($resource);
    expect($notFoundResource)->toBeNull();
});

test('can register and find a prompt', function () {
    // Arrange
    $prompt = createTestPrompt('my-prompt');

    // Act
    $this->registry->registerPrompt($prompt);
    $foundPrompt = $this->registry->findPrompt('my-prompt');
    $notFoundPrompt = $this->registry->findPrompt('nonexistent-prompt');

    // Assert
    expect($foundPrompt)->toBe($prompt);
    expect($notFoundPrompt)->toBeNull();
});

test('can register and find a resource template by URI', function () {
    // Arrange
    $template = createTestTemplate('user://{userId}/profile');

    // Act
    $this->registry->registerResourceTemplate($template);
    $match = $this->registry->findResourceTemplateByUri('user://12345/profile');
    $noMatch = $this->registry->findResourceTemplateByUri('user://12345/settings');
    $noMatchScheme = $this->registry->findResourceTemplateByUri('file://12345/profile');

    // Assert
    expect($match)->toBeArray()
        ->and($match['definition'])->toBe($template)
        ->and($match['variables'])->toBe(['userId' => '12345']);
    expect($noMatch)->toBeNull();
    expect($noMatchScheme)->toBeNull();
});

test('can retrieve all registered elements of each type', function () {
    // Arrange
    $tool1 = createTestTool('t1');
    $tool2 = createTestTool('t2');
    $resource1 = createTestResource('file:///valid/r1');
    $prompt1 = createTestPrompt('p1');
    $template1 = createTestTemplate('tmpl://{id}/data');

    $this->registry->registerTool($tool1);
    $this->registry->registerTool($tool2);
    $this->registry->registerResource($resource1);
    $this->registry->registerPrompt($prompt1);
    $this->registry->registerResourceTemplate($template1);

    // Act
    $allTools = $this->registry->allTools();
    $allResources = $this->registry->allResources();
    $allPrompts = $this->registry->allPrompts();
    $allTemplates = $this->registry->allResourceTemplates();

    // Assert
    expect($allTools)->toBeInstanceOf(\ArrayObject::class)->toHaveCount(2)->and($allTools->getArrayCopy())->toEqualCanonicalizing(['t1' => $tool1, 't2' => $tool2]);
    expect($allResources)->toBeInstanceOf(\ArrayObject::class)->toHaveCount(1)->and($allResources->getArrayCopy())->toEqual(['file:///valid/r1' => $resource1]);
    expect($allPrompts)->toBeInstanceOf(\ArrayObject::class)->toHaveCount(1)->and($allPrompts->getArrayCopy())->toEqual(['p1' => $prompt1]);
    expect($allTemplates)->toBeInstanceOf(\ArrayObject::class)->toHaveCount(1)->and($allTemplates->getArrayCopy())->toEqual(['tmpl://{id}/data' => $template1]);
});

// --- Caching Tests ---

test('can cache registered elements', function () {
    // Arrange
    $tool = createTestTool('cache-tool');
    $resource = createTestResource('cache://res');
    $prompt = createTestPrompt('cache-prompt');
    $template = createTestTemplate('cache://tmpl/{id}');

    $this->registry->registerTool($tool);
    $this->registry->registerResource($resource);
    $this->registry->registerPrompt($prompt);
    $this->registry->registerResourceTemplate($template);

    // Define expected structure using Mockery::on for flexibility
    $this->cache->shouldReceive('set')->once()
        ->with(EXPECTED_CACHE_KEY, Mockery::on(function ($data) use ($tool, $resource, $prompt, $template) {
            if (! is_array($data)) {
                return false;
            }
            if (! isset($data['tools']['cache-tool']) || $data['tools']['cache-tool'] !== $tool) {
                return false;
            }
            if (! isset($data['resources']['cache://res']) || $data['resources']['cache://res'] !== $resource) {
                return false;
            }
            if (! isset($data['prompts']['cache-prompt']) || $data['prompts']['cache-prompt'] !== $prompt) {
                return false;
            }
            if (! isset($data['resourceTemplates']['cache://tmpl/{id}']) || $data['resourceTemplates']['cache://tmpl/{id}'] !== $template) {
                return false;
            }

            return true; // Structure and objects match
        }))
        ->andReturn(true);

    // Act
    $result = $this->registry->saveElementsToCache();

    // Assert
    expect($result)->toBeTrue();
});

test('can load elements from cache', function () {
    // Arrange
    $tool = createTestTool('cached-tool');
    $resource = createTestResource('cached://res');
    $prompt = createTestPrompt('cached-prompt');
    $template = createTestTemplate('cached://tmpl/{id}');

    $cachedData = [
        'tools' => [$tool->getName() => json_decode(json_encode($tool), true)],
        'resources' => [$resource->getUri() => json_decode(json_encode($resource), true)],
        'prompts' => [$prompt->getName() => json_decode(json_encode($prompt), true)],
        'resourceTemplates' => [$template->getUriTemplate() => json_decode(json_encode($template), true)],
    ];

    $this->cache->shouldReceive('get')->once()
        ->with(EXPECTED_CACHE_KEY)
        ->andReturn($cachedData);

    // Act
    $this->registry->loadElementsFromCache(true);

    // Assert that loading occurred and elements are present
    $foundTool = $this->registry->findTool('cached-tool');
    $foundResource = $this->registry->findResourceByUri('cached://res');
    $foundPrompt = $this->registry->findPrompt('cached-prompt');
    $foundTemplateMatch = $this->registry->findResourceTemplateByUri('cached://tmpl/123');

    expect($foundTool)->toBeInstanceOf(ToolDefinition::class)
        ->and($foundTool->getName())->toBe($tool->getName())
        ->and($foundTool->getDescription())->toBe($tool->getDescription())
        ->and($foundTool->getInputSchema())->toBe($tool->getInputSchema());
    expect($foundResource)->toBeInstanceOf(ResourceDefinition::class)
        ->and($foundResource->getUri())->toBe($resource->getUri())
        ->and($foundResource->getDescription())->toBe($resource->getDescription())
        ->and($foundResource->getMimeType())->toBe($resource->getMimeType());
    expect($foundPrompt)->toBeInstanceOf(PromptDefinition::class)
        ->and($foundPrompt->getName())->toBe($prompt->getName())
        ->and($foundPrompt->getDescription())->toBe($prompt->getDescription());
    expect($foundTemplateMatch)->toBeArray()
        ->and($foundTemplateMatch['definition'])->toBeInstanceOf(ResourceTemplateDefinition::class)
        ->and($foundTemplateMatch['variables'])->toBe(['id' => '123']);

    expect($this->registry->isLoaded())->toBeTrue();
});

test('load elements ignores cache and initializes empty if cache is empty or invalid', function ($cacheReturnValue) {
    // Act
    $this->registry->loadElementsFromCache(); // loadElements returns void

    // Assert registry is empty and loaded flag is set
    expect($this->registry->allTools()->count())->toBe(0);
    expect($this->registry->allResources()->count())->toBe(0);
    expect($this->registry->allPrompts()->count())->toBe(0);
    expect($this->registry->allResourceTemplates()->count())->toBe(0);
    expect($this->registry->isLoaded())->toBeTrue(); // Should still be marked loaded

})->with([
    null,           // Cache miss
    false,          // Cache driver error return value
    [[]],          // Empty array (Corrected dataset)
    'invalid data', // Unserializable data
]);

test('can clear element cache', function () {
    // Arrange
    $this->cache->shouldReceive('delete')->once()
        ->with(EXPECTED_CACHE_KEY)
        ->andReturn(true);
    // clearCache also calls notifiers, allow any message
    $this->transportState->shouldReceive('queueMessageForAll')->atLeast()->times(1);

    // Act
    $this->registry->clearCache(); // clearCache returns void

    // Assert cache was cleared (via mock expectation) and registry is reset
    expect($this->registry->isLoaded())->toBeFalse();
    expect($this->registry->allTools()->count())->toBe(0);
});

// --- Notifier Tests ---

test('can set and trigger notifiers', function () {
    // Arrange
    $toolNotifierCalled = false;
    $resourceNotifierCalled = false;
    $promptNotifierCalled = false;

    $this->registry->setToolsChangedNotifier(function () use (&$toolNotifierCalled) {
        $toolNotifierCalled = true;
    });
    $this->registry->setResourcesChangedNotifier(function () use (&$resourceNotifierCalled) {
        $resourceNotifierCalled = true;
    });
    $this->registry->setPromptsChangedNotifier(function () use (&$promptNotifierCalled) {
        $promptNotifierCalled = true;
    });

    // Act - Register elements to trigger notifiers
    $this->registry->registerTool(createTestTool());
    $this->registry->registerResource(createTestResource());
    $this->registry->registerPrompt(createTestPrompt());

    // Assert
    expect($toolNotifierCalled)->toBeTrue();
    expect($resourceNotifierCalled)->toBeTrue();
    expect($promptNotifierCalled)->toBeTrue();
});

test('notifiers are not called if not set', function () {
    // Arrange
    // Use default null notifiers
    $this->registry->setToolsChangedNotifier(null);
    $this->registry->setResourcesChangedNotifier(null);
    $this->registry->setPromptsChangedNotifier(null);

    // Act & Assert - Expect no exceptions when registering (which calls notify methods)
    expect(fn () => $this->registry->registerTool(createTestTool()))->not->toThrow(Throwable::class);
    expect(fn () => $this->registry->registerResource(createTestResource()))->not->toThrow(Throwable::class);
    expect(fn () => $this->registry->registerPrompt(createTestPrompt()))->not->toThrow(Throwable::class);
    // Ensure default notifiers (sending via transport) are not called
    $this->transportState->shouldNotReceive('queueMessageForAll');
});
