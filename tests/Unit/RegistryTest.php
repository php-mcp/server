<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Registry;
use PhpMcp\Server\State\ClientStateManager;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

const DISCOVERED_CACHE_KEY = 'mcp_server_discovered_elements';

function createTestTool(string $name = 'test-tool'): ToolDefinition
{
    return new ToolDefinition('TestClass', 'toolMethod', $name, 'Desc ' . $name, ['type' => 'object']);
}
function createTestResource(string $uri = 'test://res', string $name = 'test-res'): ResourceDefinition
{
    return new ResourceDefinition('TestClass', 'resourceMethod', $uri, $name, 'Desc ' . $name, 'text/plain', 100, []);
}
function createTestPrompt(string $name = 'test-prompt'): PromptDefinition
{
    return new PromptDefinition('TestClass', 'promptMethod', $name, 'Desc ' . $name, []);
}
function createTestTemplate(string $uriTemplate = 'tmpl://{id}', string $name = 'test-tmpl'): ResourceTemplateDefinition
{
    return new ResourceTemplateDefinition('TestClass', 'templateMethod', $uriTemplate, $name, 'Desc ' . $name, 'application/json', []);
}

beforeEach(function () {
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    /** @var MockInterface&CacheInterface */
    $this->cache = Mockery::mock(CacheInterface::class);
    /** @var MockInterface&ClientStateManager */
    $this->clientStateManager = Mockery::mock(ClientStateManager::class)->shouldIgnoreMissing();

    $this->cache->allows('get')->with(DISCOVERED_CACHE_KEY)->andReturn(null)->byDefault();
    $this->cache->allows('set')->with(DISCOVERED_CACHE_KEY, Mockery::any())->andReturn(true)->byDefault();
    $this->cache->allows('delete')->with(DISCOVERED_CACHE_KEY)->andReturn(true)->byDefault();

    $this->registry = new Registry($this->logger, $this->cache, $this->clientStateManager);
    $this->registryNoCache = new Registry($this->logger, null, $this->clientStateManager);
});

function getRegistryProperty(Registry $reg, string $propName)
{
    $reflector = new \ReflectionClass($reg);
    $prop = $reflector->getProperty($propName);
    $prop->setAccessible(true);

    return $prop->getValue($reg);
}

// --- Basic Registration & Retrieval ---

it('registers manual tool and marks as manual', function () {
    // Arrange
    $tool = createTestTool('manual-tool-1');

    // Act
    $this->registry->registerTool($tool, true); // Register as manual

    // Assert
    expect($this->registry->findTool('manual-tool-1'))->toBe($tool);
    expect($this->registry->allTools())->toHaveCount(1);
    expect(getRegistryProperty($this->registry, 'manualToolNames'))->toHaveKey('manual-tool-1');
});

it('registers discovered tool', function () {
    // Arrange
    $tool = createTestTool('discovered-tool-1');

    // Act
    $this->registry->registerTool($tool, false); // Register as discovered

    // Assert
    expect($this->registry->findTool('discovered-tool-1'))->toBe($tool);
    expect($this->registry->allTools())->toHaveCount(1);
    expect(getRegistryProperty($this->registry, 'manualToolNames'))->toBeEmpty();
});

it('registers manual resource and marks as manual', function () {
    // Arrange
    $res = createTestResource('manual://res/1');

    // Act
    $this->registry->registerResource($res, true);

    // Assert
    expect($this->registry->findResourceByUri('manual://res/1'))->toBe($res);
    expect(getRegistryProperty($this->registry, 'manualResourceUris'))->toHaveKey('manual://res/1');
});

it('registers discovered resource', function () {
    // Arrange
    $res = createTestResource('discovered://res/1');

    // Act
    $this->registry->registerResource($res, false);

    // Assert
    expect($this->registry->findResourceByUri('discovered://res/1'))->toBe($res);
    expect(getRegistryProperty($this->registry, 'manualResourceUris'))->toBeEmpty();
});

it('registers manual prompt and marks as manual', function () {
    // Arrange
    $prompt = createTestPrompt('manual-prompt');

    // Act
    $this->registry->registerPrompt($prompt, true);

    // Assert
    expect($this->registry->findPrompt('manual-prompt'))->toBe($prompt);
    expect(getRegistryProperty($this->registry, 'manualPromptNames'))->toHaveKey('manual-prompt');
});

it('registers discovered prompt', function () {
    // Arrange
    $prompt = createTestPrompt('discovered-prompt');

    // Act
    $this->registry->registerPrompt($prompt, false);

    // Assert
    expect($this->registry->findPrompt('discovered-prompt'))->toBe($prompt);
    expect(getRegistryProperty($this->registry, 'manualPromptNames'))->toBeEmpty();
});

it('registers manual template and marks as manual', function () {
    // Arrange
    $template = createTestTemplate('manual://tmpl/{id}');

    // Act
    $this->registry->registerResourceTemplate($template, true);

    // Assert
    expect($this->registry->findResourceTemplateByUri('manual://tmpl/123')['definition'] ?? null)->toBe($template);
    expect(getRegistryProperty($this->registry, 'manualTemplateUris'))->toHaveKey('manual://tmpl/{id}');
});

it('registers discovered template', function () {
    // Arrange
    $template = createTestTemplate('discovered://tmpl/{id}');

    // Act
    $this->registry->registerResourceTemplate($template, false);

    // Assert
    expect($this->registry->findResourceTemplateByUri('discovered://tmpl/abc')['definition'] ?? null)->toBe($template);
    expect(getRegistryProperty($this->registry, 'manualTemplateUris'))->toBeEmpty();
});

test('hasElements returns true if manual elements exist', function () {
    // Arrange
    expect($this->registry->hasElements())->toBeFalse(); // Starts empty

    // Act
    $this->registry->registerTool(createTestTool('manual-only'), true);

    // Assert
    expect($this->registry->hasElements())->toBeTrue();
});

test('hasElements returns true if discovered elements exist', function () {
    // Arrange
    expect($this->registry->hasElements())->toBeFalse();

    // Act
    $this->registry->registerTool(createTestTool('discovered-only'), false);

    // Assert
    expect($this->registry->hasElements())->toBeTrue();
});

// --- Registration Precedence ---

it('overrides existing discovered element with manual registration', function () {
    // Arrange
    $toolName = 'override-test';
    $discoveredTool = createTestTool($toolName); // Version 1 (Discovered)
    $manualTool = createTestTool($toolName); // Version 2 (Manual) - different instance

    // Act
    $this->registry->registerTool($discoveredTool, false); // Register discovered first

    // Assert
    expect($this->registry->findTool($toolName))->toBe($discoveredTool);

    $this->logger->shouldReceive('warning')->with(Mockery::pattern("/Replacing existing discovered tool '{$toolName}' with manual/"))->once();

    // Act
    $this->registry->registerTool($manualTool, true);

    // Assert manual version is now stored
    expect($this->registry->findTool($toolName))->toBe($manualTool);
    // Assert it's marked as manual
    $reflector = new \ReflectionClass($this->registry);
    $manualNamesProp = $reflector->getProperty('manualToolNames');
    $manualNamesProp->setAccessible(true);
    expect($manualNamesProp->getValue($this->registry))->toHaveKey($toolName);
});

it('does not override existing manual element with discovered registration', function () {
    // Arrange
    $toolName = 'manual-priority';
    $manualTool = createTestTool($toolName); // Version 1 (Manual)
    $discoveredTool = createTestTool($toolName); // Version 2 (Discovered)

    // Act
    $this->registry->registerTool($manualTool, true); // Register manual first

    // Assert
    expect($this->registry->findTool($toolName))->toBe($manualTool);

    // Expect debug log when ignoring
    $this->logger->shouldReceive('debug')->with(Mockery::pattern("/Ignoring discovered tool '{$toolName}' as it conflicts/"))->once();

    // Attempt to register discovered version
    $this->registry->registerTool($discoveredTool, false);

    // Assert manual version is STILL stored
    expect($this->registry->findTool($toolName))->toBe($manualTool);
    // Assert it's still marked as manual
    $reflector = new \ReflectionClass($this->registry);
    $manualNamesProp = $reflector->getProperty('manualToolNames');
    $manualNamesProp->setAccessible(true);
    expect($manualNamesProp->getValue($this->registry))->toHaveKey($toolName);
});

// --- Caching Logic ---

it('loads discovered elements from cache correctly', function () {
    // Arrange
    $cachedTool = createTestTool('cached-tool-constructor');
    $cachedResource = createTestResource('cached://res-constructor');
    $cachedData = [
        'tools' => [$cachedTool->toolName => $cachedTool],
        'resources' => [$cachedResource->uri => $cachedResource],
        'prompts' => [],
        'resourceTemplates' => [],
    ];
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY)->once()->andReturn($cachedData);

    // Act
    $registry = new Registry($this->logger, $this->cache, $this->clientStateManager);

    // Assertions
    expect($registry->findTool('cached-tool-constructor'))->toBeInstanceOf(ToolDefinition::class);
    expect($registry->findResourceByUri('cached://res-constructor'))->toBeInstanceOf(ResourceDefinition::class);
    expect($registry->discoveryRanOrCached())->toBeTrue();
    // Check nothing was marked as manual
    expect(getRegistryProperty($registry, 'manualToolNames'))->toBeEmpty();
    expect(getRegistryProperty($registry, 'manualResourceUris'))->toBeEmpty();
});

it('skips cache items conflicting with LATER manual registration', function () {
    // Arrange
    $conflictName = 'conflict-tool';
    $manualTool = createTestTool($conflictName);
    $cachedToolData = createTestTool($conflictName); // Tool with same name in cache

    $cachedData = ['tools' => [$conflictName => $cachedToolData]];
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY)->once()->andReturn($cachedData);

    // Act
    $registry = new Registry($this->logger, $this->cache, $this->clientStateManager);

    // Assert the cached item IS initially loaded (because manual isn't there *yet*)
    $toolBeforeManual = $registry->findTool($conflictName);
    expect($toolBeforeManual)->toBeInstanceOf(ToolDefinition::class);
    expect(getRegistryProperty($registry, 'manualToolNames'))->toBeEmpty(); // Not manual yet

    // NOW, register the manual one (simulating builder doing it after constructing Registry)
    $this->logger->shouldReceive('warning')->with(Mockery::pattern("/Replacing existing discovered tool '{$conflictName}'/"))->once(); // Expect replace warning
    $registry->registerTool($manualTool, true);

    // Assert manual version is now present and marked correctly
    expect($registry->findTool($conflictName))->toBe($manualTool);
    expect(getRegistryProperty($registry, 'manualToolNames'))->toHaveKey($conflictName);
});

it('saves only non-manual elements to cache', function () {
    // Arrange
    $manualTool = createTestTool('manual-save');
    $discoveredTool = createTestTool('discovered-save');
    $expectedCachedData = [
        'tools' => ['discovered-save' => $discoveredTool],
        'resources' => [],
        'prompts' => [],
        'resourceTemplates' => [],
    ];

    // Act
    $this->registry->registerTool($manualTool, true);
    $this->registry->registerTool($discoveredTool, false);

    $this->cache->shouldReceive('set')->once()
        ->with(DISCOVERED_CACHE_KEY, $expectedCachedData) // Expect EXACT filtered data
        ->andReturn(true);

    $result = $this->registry->saveDiscoveredElementsToCache();
    expect($result)->toBeTrue();
});

it('ignores non-array cache data', function () {
    // Arrange
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY)->once()->andReturn('invalid string data');

    // Act
    $registry = new Registry($this->logger, $this->cache, $this->clientStateManager);

    // Assert
    expect($registry->discoveryRanOrCached())->toBeFalse(); // Marked loaded
    expect($registry->hasElements())->toBeFalse(); // But empty
});

it('ignores cache on hydration error', function () {
    // Arrange
    $invalidToolData = ['toolName' => 'good-name', 'description' => 'good-desc', 'inputSchema' => 'not-an-array', 'className' => 'TestClass', 'methodName' => 'toolMethod']; // Invalid schema
    $cachedData = ['tools' => ['good-name' => $invalidToolData]];
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY)->once()->andReturn($cachedData);

    // Act
    $registry = new Registry($this->logger, $this->cache, $this->clientStateManager);

    // Assert
    expect($registry->discoveryRanOrCached())->toBeFalse();
    expect($registry->hasElements())->toBeFalse(); // Hydration failed
});

it('removes only non-manual elements and optionally clears cache', function ($deleteCacheFile) {
    // Arrange
    $manualTool = createTestTool('manual-clear');
    $discoveredTool = createTestTool('discovered-clear');
    $manualResource = createTestResource('manual://clear');
    $discoveredResource = createTestResource('discovered://clear');

    // Act
    $this->registry->registerTool($manualTool, true);
    $this->registry->registerTool($discoveredTool, false);
    $this->registry->registerResource($manualResource, true);
    $this->registry->registerResource($discoveredResource, false);

    // Assert
    expect($this->registry->allTools())->toHaveCount(2);
    expect($this->registry->allResources())->toHaveCount(2);

    if ($deleteCacheFile) {
        $this->cache->shouldReceive('delete')->with(DISCOVERED_CACHE_KEY)->once()->andReturn(true);
    } else {
        $this->cache->shouldNotReceive('delete');
    }

    // Act
    $this->registry->clearDiscoveredElements($deleteCacheFile);

    // Assert: Manual elements remain, discovered are gone
    expect($this->registry->findTool('manual-clear'))->toBe($manualTool);
    expect($this->registry->findTool('discovered-clear'))->toBeNull();
    expect($this->registry->findResourceByUri('manual://clear'))->toBe($manualResource);
    expect($this->registry->findResourceByUri('discovered://clear'))->toBeNull();
    expect($this->registry->allTools())->toHaveCount(1);
    expect($this->registry->allResources())->toHaveCount(1);
    expect($this->registry->discoveryRanOrCached())->toBeFalse(); // Flag should be reset

})->with([
    'Delete Cache File' => [true],
    'Keep Cache File' => [false],
]);

// --- Notifier Tests ---

it('sends notifications when tools, resources, and prompts are registered', function () {
    // Arrange
    $tool = createTestTool('notify-tool');
    $resource = createTestResource('notify://res');
    $prompt = createTestPrompt('notify-prompt');

    $this->clientStateManager->shouldReceive('queueMessageForAll')->times(3)->with(Mockery::type('string'));

    // Act
    $this->registry->registerTool($tool);
    $this->registry->registerResource($resource);
    $this->registry->registerPrompt($prompt);
});

it('does not send notifications when notifications are disabled', function () {
    // Arrange
    $this->registry->disableNotifications();

    $this->clientStateManager->shouldNotReceive('queueMessageForAll');

    // Act
    $this->registry->registerTool(createTestTool('notify-tool'));
});
