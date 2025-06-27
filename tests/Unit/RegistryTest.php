<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Schema\Prompt;
use PhpMcp\Schema\Resource;
use PhpMcp\Schema\ResourceTemplate;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Elements\RegisteredPrompt;
use PhpMcp\Server\Elements\RegisteredResource;
use PhpMcp\Server\Elements\RegisteredResourceTemplate;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Server\Registry;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;

const DISCOVERED_CACHE_KEY_REG = 'mcp_server_discovered_elements';

function createTestToolSchema(string $name = 'test-tool'): Tool
{
    return Tool::make(name: $name, inputSchema: ['type' => 'object'], description: 'Desc ' . $name);
}

function createTestResourceSchema(string $uri = 'test://res', string $name = 'test-res'): Resource
{
    return Resource::make(uri: $uri, name: $name, description: 'Desc ' . $name, mimeType: 'text/plain');
}

function createTestPromptSchema(string $name = 'test-prompt'): Prompt
{
    return Prompt::make(name: $name, description: 'Desc ' . $name, arguments: []);
}

function createTestTemplateSchema(string $uriTemplate = 'tmpl://{id}', string $name = 'test-tmpl'): ResourceTemplate
{
    return ResourceTemplate::make(uriTemplate: $uriTemplate, name: $name, description: 'Desc ' . $name, mimeType: 'application/json');
}

beforeEach(function () {
    /** @var MockInterface&LoggerInterface $logger */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    /** @var MockInterface&CacheInterface $cache */
    $this->cache = Mockery::mock(CacheInterface::class);

    // Default cache behavior: miss on get, success on set/delete
    $this->cache->allows('get')->with(DISCOVERED_CACHE_KEY_REG)->andReturn(null)->byDefault();
    $this->cache->allows('set')->with(DISCOVERED_CACHE_KEY_REG, Mockery::any())->andReturn(true)->byDefault();
    $this->cache->allows('delete')->with(DISCOVERED_CACHE_KEY_REG)->andReturn(true)->byDefault();

    $this->registry = new Registry($this->logger, $this->cache);
    $this->registryNoCache = new Registry($this->logger, null);
});

function getRegistryProperty(Registry $reg, string $propName)
{
    $reflector = new \ReflectionClass($reg);
    $prop = $reflector->getProperty($propName);
    $prop->setAccessible(true);
    return $prop->getValue($reg);
}

it('registers manual tool correctly', function () {
    $toolSchema = createTestToolSchema('manual-tool-1');
    $this->registry->registerTool($toolSchema, ['HandlerClass', 'method'], true);

    $registeredTool = $this->registry->getTool('manual-tool-1');
    expect($registeredTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($registeredTool->schema)->toBe($toolSchema)
        ->and($registeredTool->isManual)->toBeTrue();
    expect($this->registry->getTools())->toHaveKey('manual-tool-1');
});

it('registers discovered tool correctly', function () {
    $toolSchema = createTestToolSchema('discovered-tool-1');
    $this->registry->registerTool($toolSchema, ['HandlerClass', 'method'], false);

    $registeredTool = $this->registry->getTool('discovered-tool-1');
    expect($registeredTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($registeredTool->schema)->toBe($toolSchema)
        ->and($registeredTool->isManual)->toBeFalse();
});

it('registers manual resource correctly', function () {
    $resourceSchema = createTestResourceSchema('manual://res/1');
    $this->registry->registerResource($resourceSchema, ['HandlerClass', 'method'], true);

    $registeredResource = $this->registry->getResource('manual://res/1');
    expect($registeredResource)->toBeInstanceOf(RegisteredResource::class)
        ->and($registeredResource->schema)->toBe($resourceSchema)
        ->and($registeredResource->isManual)->toBeTrue();
    expect($this->registry->getResources())->toHaveKey('manual://res/1');
});

it('registers discovered resource correctly', function () {
    $resourceSchema = createTestResourceSchema('discovered://res/1');
    $this->registry->registerResource($resourceSchema, ['HandlerClass', 'method'], false);

    $registeredResource = $this->registry->getResource('discovered://res/1');
    expect($registeredResource)->toBeInstanceOf(RegisteredResource::class)
        ->and($registeredResource->schema)->toBe($resourceSchema)
        ->and($registeredResource->isManual)->toBeFalse();
});

it('registers manual prompt correctly', function () {
    $promptSchema = createTestPromptSchema('manual-prompt-1');
    $this->registry->registerPrompt($promptSchema, ['HandlerClass', 'method'], [], true);

    $registeredPrompt = $this->registry->getPrompt('manual-prompt-1');
    expect($registeredPrompt)->toBeInstanceOf(RegisteredPrompt::class)
        ->and($registeredPrompt->schema)->toBe($promptSchema)
        ->and($registeredPrompt->isManual)->toBeTrue();
    expect($this->registry->getPrompts())->toHaveKey('manual-prompt-1');
});

it('registers discovered prompt correctly', function () {
    $promptSchema = createTestPromptSchema('discovered-prompt-1');
    $this->registry->registerPrompt($promptSchema, ['HandlerClass', 'method'], [], false);

    $registeredPrompt = $this->registry->getPrompt('discovered-prompt-1');
    expect($registeredPrompt)->toBeInstanceOf(RegisteredPrompt::class)
        ->and($registeredPrompt->schema)->toBe($promptSchema)
        ->and($registeredPrompt->isManual)->toBeFalse();
});

it('registers manual resource template correctly', function () {
    $templateSchema = createTestTemplateSchema('manual://tmpl/{id}');
    $this->registry->registerResourceTemplate($templateSchema, ['HandlerClass', 'method'], [], true);

    $registeredTemplate = $this->registry->getResourceTemplate('manual://tmpl/{id}');
    expect($registeredTemplate)->toBeInstanceOf(RegisteredResourceTemplate::class)
        ->and($registeredTemplate->schema)->toBe($templateSchema)
        ->and($registeredTemplate->isManual)->toBeTrue();
    expect($this->registry->getResourceTemplates())->toHaveKey('manual://tmpl/{id}');
});

it('registers discovered resource template correctly', function () {
    $templateSchema = createTestTemplateSchema('discovered://tmpl/{id}');
    $this->registry->registerResourceTemplate($templateSchema, ['HandlerClass', 'method'], [], false);

    $registeredTemplate = $this->registry->getResourceTemplate('discovered://tmpl/{id}');
    expect($registeredTemplate)->toBeInstanceOf(RegisteredResourceTemplate::class)
        ->and($registeredTemplate->schema)->toBe($templateSchema)
        ->and($registeredTemplate->isManual)->toBeFalse();
});

test('getResource finds exact URI match before template match', function () {
    $exactResourceSchema = createTestResourceSchema('test://item/exact');
    $templateSchema = createTestTemplateSchema('test://item/{itemId}');

    $this->registry->registerResource($exactResourceSchema, ['H', 'm']);
    $this->registry->registerResourceTemplate($templateSchema, ['H', 'm']);

    $found = $this->registry->getResource('test://item/exact');
    expect($found)->toBeInstanceOf(RegisteredResource::class)
        ->and($found->schema->uri)->toBe('test://item/exact');
});

test('getResource finds template match if no exact URI match', function () {
    $templateSchema = createTestTemplateSchema('test://item/{itemId}');
    $this->registry->registerResourceTemplate($templateSchema, ['H', 'm']);

    $found = $this->registry->getResource('test://item/123');
    expect($found)->toBeInstanceOf(RegisteredResourceTemplate::class)
        ->and($found->schema->uriTemplate)->toBe('test://item/{itemId}');
});

test('getResource returns null if no match and templates excluded', function () {
    $templateSchema = createTestTemplateSchema('test://item/{itemId}');
    $this->registry->registerResourceTemplate($templateSchema, ['H', 'm']);

    $found = $this->registry->getResource('test://item/123', false);
    expect($found)->toBeNull();
});

test('getResource returns null if no match at all', function () {
    $found = $this->registry->getResource('nonexistent://uri');
    expect($found)->toBeNull();
});

it('hasElements returns true if any manual elements exist', function () {
    expect($this->registry->hasElements())->toBeFalse();
    $this->registry->registerTool(createTestToolSchema('manual-only'), ['H', 'm'], true);
    expect($this->registry->hasElements())->toBeTrue();
});

it('hasElements returns true if any discovered elements exist', function () {
    expect($this->registry->hasElements())->toBeFalse();
    $this->registry->registerTool(createTestToolSchema('discovered-only'), ['H', 'm'], false);
    expect($this->registry->hasElements())->toBeTrue();
});

it('overrides existing discovered element with manual registration', function (string $type) {
    $nameOrUri = $type === 'resource' ? 'conflict://res' : 'conflict-element';
    $templateUri = 'conflict://tmpl/{id}';

    $discoveredSchema = match ($type) {
        'tool' => createTestToolSchema($nameOrUri),
        'resource' => createTestResourceSchema($nameOrUri),
        'prompt' => createTestPromptSchema($nameOrUri),
        'template' => createTestTemplateSchema($templateUri),
    };
    $manualSchema = clone $discoveredSchema;

    match ($type) {
        'tool' => $this->registry->registerTool($discoveredSchema, ['H', 'm'], false),
        'resource' => $this->registry->registerResource($discoveredSchema, ['H', 'm'], false),
        'prompt' => $this->registry->registerPrompt($discoveredSchema, ['H', 'm'], [], false),
        'template' => $this->registry->registerResourceTemplate($discoveredSchema, ['H', 'm'], [], false),
    };

    match ($type) {
        'tool' => $this->registry->registerTool($manualSchema, ['H', 'm'], true),
        'resource' => $this->registry->registerResource($manualSchema, ['H', 'm'], true),
        'prompt' => $this->registry->registerPrompt($manualSchema, ['H', 'm'], [], true),
        'template' => $this->registry->registerResourceTemplate($manualSchema, ['H', 'm'], [], true),
    };

    $registeredElement = match ($type) {
        'tool' => $this->registry->getTool($nameOrUri),
        'resource' => $this->registry->getResource($nameOrUri),
        'prompt' => $this->registry->getPrompt($nameOrUri),
        'template' => $this->registry->getResourceTemplate($templateUri),
    };

    expect($registeredElement->schema)->toBe($manualSchema);
    expect($registeredElement->isManual)->toBeTrue();
})->with(['tool', 'resource', 'prompt', 'template']);

it('does not override existing manual element with discovered registration', function (string $type) {
    $nameOrUri = $type === 'resource' ? 'manual-priority://res' : 'manual-priority-element';
    $templateUri = 'manual-priority://tmpl/{id}';

    $manualSchema = match ($type) {
        'tool' => createTestToolSchema($nameOrUri),
        'resource' => createTestResourceSchema($nameOrUri),
        'prompt' => createTestPromptSchema($nameOrUri),
        'template' => createTestTemplateSchema($templateUri),
    };
    $discoveredSchema = clone $manualSchema;

    match ($type) {
        'tool' => $this->registry->registerTool($manualSchema, ['H', 'm'], true),
        'resource' => $this->registry->registerResource($manualSchema, ['H', 'm'], true),
        'prompt' => $this->registry->registerPrompt($manualSchema, ['H', 'm'], [], true),
        'template' => $this->registry->registerResourceTemplate($manualSchema, ['H', 'm'], [], true),
    };

    match ($type) {
        'tool' => $this->registry->registerTool($discoveredSchema, ['H', 'm'], false),
        'resource' => $this->registry->registerResource($discoveredSchema, ['H', 'm'], false),
        'prompt' => $this->registry->registerPrompt($discoveredSchema, ['H', 'm'], [], false),
        'template' => $this->registry->registerResourceTemplate($discoveredSchema, ['H', 'm'], [], false),
    };

    $registeredElement = match ($type) {
        'tool' => $this->registry->getTool($nameOrUri),
        'resource' => $this->registry->getResource($nameOrUri),
        'prompt' => $this->registry->getPrompt($nameOrUri),
        'template' => $this->registry->getResourceTemplate($templateUri),
    };

    expect($registeredElement->schema)->toBe($manualSchema);
    expect($registeredElement->isManual)->toBeTrue();
})->with(['tool', 'resource', 'prompt', 'template']);


it('loads discovered elements from cache correctly on construction', function () {
    $toolSchema1 = createTestToolSchema('cached-tool-1');
    $resourceSchema1 = createTestResourceSchema('cached://res/1');
    $cachedData = [
        'tools' => [$toolSchema1->name => json_encode(RegisteredTool::make($toolSchema1, ['H', 'm']))],
        'resources' => [$resourceSchema1->uri => json_encode(RegisteredResource::make($resourceSchema1, ['H', 'm']))],
        'prompts' => [],
        'resourceTemplates' => [],
    ];
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY_REG)->once()->andReturn($cachedData);

    $registry = new Registry($this->logger, $this->cache);

    expect($registry->getTool('cached-tool-1'))->toBeInstanceOf(RegisteredTool::class)
        ->and($registry->getTool('cached-tool-1')->isManual)->toBeFalse();
    expect($registry->getResource('cached://res/1'))->toBeInstanceOf(RegisteredResource::class)
        ->and($registry->getResource('cached://res/1')->isManual)->toBeFalse();
    expect($registry->hasElements())->toBeTrue();
});

it('skips loading cached element if manual one with same key is registered later', function () {
    $conflictName = 'conflict-tool';
    $cachedToolSchema = createTestToolSchema($conflictName);
    $manualToolSchema = createTestToolSchema($conflictName); // Different instance

    $cachedData = ['tools' => [$conflictName => json_encode(RegisteredTool::make($cachedToolSchema, ['H', 'm']))]];
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY_REG)->once()->andReturn($cachedData);

    $registry = new Registry($this->logger, $this->cache);

    expect($registry->getTool($conflictName)->schema->name)->toBe($cachedToolSchema->name);
    expect($registry->getTool($conflictName)->isManual)->toBeFalse();

    $registry->registerTool($manualToolSchema, ['H', 'm'], true);

    expect($registry->getTool($conflictName)->schema->name)->toBe($manualToolSchema->name);
    expect($registry->getTool($conflictName)->isManual)->toBeTrue();
});


it('saves only non-manual elements to cache', function () {
    $manualToolSchema = createTestToolSchema('manual-save');
    $discoveredToolSchema = createTestToolSchema('discovered-save');
    $expectedRegisteredDiscoveredTool = RegisteredTool::make($discoveredToolSchema, ['H', 'm'], false);

    $this->registry->registerTool($manualToolSchema, ['H', 'm'], true);
    $this->registry->registerTool($discoveredToolSchema, ['H', 'm'], false);

    $expectedCachedData = [
        'tools' => ['discovered-save' => json_encode($expectedRegisteredDiscoveredTool)],
        'resources' => [],
        'prompts' => [],
        'resourceTemplates' => [],
    ];

    $this->cache->shouldReceive('set')->once()
        ->with(DISCOVERED_CACHE_KEY_REG, $expectedCachedData)
        ->andReturn(true);

    $result = $this->registry->save();
    expect($result)->toBeTrue();
});

it('does not attempt to save to cache if cache is null', function () {
    $this->registryNoCache->registerTool(createTestToolSchema('discovered-no-cache'), ['H', 'm'], false);
    $result = $this->registryNoCache->save();
    expect($result)->toBeFalse();
});

it('handles invalid (non-array) data from cache gracefully during load', function () {
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY_REG)->once()->andReturn('this is not an array');
    $this->logger->shouldReceive('warning')->with(Mockery::pattern('/Invalid or missing data found in registry cache/'), Mockery::any())->once();

    $registry = new Registry($this->logger, $this->cache);

    expect($registry->hasElements())->toBeFalse();
});

it('handles cache unserialization errors gracefully during load', function () {
    $badSerializedData = ['tools' => ['bad-tool' => 'not a serialized object']];
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY_REG)->once()->andReturn($badSerializedData);

    $registry = new Registry($this->logger, $this->cache);

    expect($registry->hasElements())->toBeFalse();
});

it('handles cache general exceptions during load gracefully', function () {
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY_REG)->once()->andThrow(new \RuntimeException('Cache unavailable'));

    $registry = new Registry($this->logger, $this->cache);

    expect($registry->hasElements())->toBeFalse();
});

it('handles cache InvalidArgumentException during load gracefully', function () {
    $this->cache->shouldReceive('get')->with(DISCOVERED_CACHE_KEY_REG)->once()->andThrow(new class() extends \Exception implements CacheInvalidArgumentException {});

    $registry = new Registry($this->logger, $this->cache);
    expect($registry->hasElements())->toBeFalse();
});


it('clears non-manual elements and deletes cache file', function () {
    $this->registry->registerTool(createTestToolSchema('manual-clear'), ['H', 'm'], true);
    $this->registry->registerTool(createTestToolSchema('discovered-clear'), ['H', 'm'], false);

    $this->cache->shouldReceive('delete')->with(DISCOVERED_CACHE_KEY_REG)->once()->andReturn(true);

    $this->registry->clear();

    expect($this->registry->getTool('manual-clear'))->not->toBeNull();
    expect($this->registry->getTool('discovered-clear'))->toBeNull();
});


it('handles cache exceptions during clear gracefully', function () {
    $this->registry->registerTool(createTestToolSchema('discovered-clear'), ['H', 'm'], false);
    $this->cache->shouldReceive('delete')->with(DISCOVERED_CACHE_KEY_REG)->once()->andThrow(new \RuntimeException("Cache delete failed"));

    $this->registry->clear();

    expect($this->registry->getTool('discovered-clear'))->toBeNull();
});

it('emits list_changed event when a new tool is registered', function () {
    $emitted = null;
    $this->registry->on('list_changed', function ($listType) use (&$emitted) {
        $emitted = $listType;
    });

    $this->registry->registerTool(createTestToolSchema('notifying-tool'), ['H', 'm']);
    expect($emitted)->toBe('tools');
});

it('emits list_changed event when a new resource is registered', function () {
    $emitted = null;
    $this->registry->on('list_changed', function ($listType) use (&$emitted) {
        $emitted = $listType;
    });

    $this->registry->registerResource(createTestResourceSchema('notify://res'), ['H', 'm']);
    expect($emitted)->toBe('resources');
});

it('does not emit list_changed event if notifications are disabled', function () {
    $this->registry->disableNotifications();
    $emitted = false;
    $this->registry->on('list_changed', function () use (&$emitted) {
        $emitted = true;
    });

    $this->registry->registerTool(createTestToolSchema('silent-tool'), ['H', 'm']);
    expect($emitted)->toBeFalse();

    $this->registry->enableNotifications();
});

it('computes different hashes for different collections', function () {
    $method = new \ReflectionMethod(Registry::class, 'computeHash');
    $method->setAccessible(true);

    $hash1 = $method->invoke($this->registry, ['a' => 1, 'b' => 2]);
    $hash2 = $method->invoke($this->registry, ['b' => 2, 'a' => 1]);
    $hash3 = $method->invoke($this->registry, ['a' => 1, 'c' => 3]);

    expect($hash1)->toBeString()->not->toBeEmpty();
    expect($hash2)->toBe($hash1);
    expect($hash3)->not->toBe($hash1);
    expect($method->invoke($this->registry, []))->toBe('');
});

it('recomputes and emits list_changed only when content actually changes', function () {
    $tool1 = createTestToolSchema('tool1');
    $tool2 = createTestToolSchema('tool2');
    $callCount = 0;

    $this->registry->on('list_changed', function ($listType) use (&$callCount) {
        if ($listType === 'tools') {
            $callCount++;
        }
    });

    $this->registry->registerTool($tool1, ['H', 'm1']);
    expect($callCount)->toBe(1);

    $this->registry->registerTool($tool1, ['H', 'm1']);
    expect($callCount)->toBe(1);

    $this->registry->registerTool($tool2, ['H', 'm2']);
    expect($callCount)->toBe(2);
});

it('registers tool with closure handler correctly', function () {
    $toolSchema = createTestToolSchema('closure-tool');
    $closure = function (string $input): string {
        return "processed: $input";
    };

    $this->registry->registerTool($toolSchema, $closure, true);

    $registeredTool = $this->registry->getTool('closure-tool');
    expect($registeredTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($registeredTool->schema)->toBe($toolSchema)
        ->and($registeredTool->isManual)->toBeTrue()
        ->and($registeredTool->handler)->toBe($closure);
});

it('registers resource with closure handler correctly', function () {
    $resourceSchema = createTestResourceSchema('closure://res');
    $closure = function (string $uri): array {
        return [new \PhpMcp\Schema\Content\TextContent("Resource: $uri")];
    };

    $this->registry->registerResource($resourceSchema, $closure, true);

    $registeredResource = $this->registry->getResource('closure://res');
    expect($registeredResource)->toBeInstanceOf(RegisteredResource::class)
        ->and($registeredResource->schema)->toBe($resourceSchema)
        ->and($registeredResource->isManual)->toBeTrue()
        ->and($registeredResource->handler)->toBe($closure);
});

it('registers prompt with closure handler correctly', function () {
    $promptSchema = createTestPromptSchema('closure-prompt');
    $closure = function (string $topic): array {
        return [
            \PhpMcp\Schema\Content\PromptMessage::make(
                \PhpMcp\Schema\Enum\Role::User,
                new \PhpMcp\Schema\Content\TextContent("Tell me about $topic")
            )
        ];
    };

    $this->registry->registerPrompt($promptSchema, $closure, [], true);

    $registeredPrompt = $this->registry->getPrompt('closure-prompt');
    expect($registeredPrompt)->toBeInstanceOf(RegisteredPrompt::class)
        ->and($registeredPrompt->schema)->toBe($promptSchema)
        ->and($registeredPrompt->isManual)->toBeTrue()
        ->and($registeredPrompt->handler)->toBe($closure);
});

it('registers resource template with closure handler correctly', function () {
    $templateSchema = createTestTemplateSchema('closure://item/{id}');
    $closure = function (string $uri, string $id): array {
        return [new \PhpMcp\Schema\Content\TextContent("Item $id from $uri")];
    };

    $this->registry->registerResourceTemplate($templateSchema, $closure, [], true);

    $registeredTemplate = $this->registry->getResourceTemplate('closure://item/{id}');
    expect($registeredTemplate)->toBeInstanceOf(RegisteredResourceTemplate::class)
        ->and($registeredTemplate->schema)->toBe($templateSchema)
        ->and($registeredTemplate->isManual)->toBeTrue()
        ->and($registeredTemplate->handler)->toBe($closure);
});

it('does not save closure handlers to cache', function () {
    $closure = function (): string {
        return 'test';
    };
    $arrayHandler = ['TestClass', 'testMethod'];

    $closureTool = createTestToolSchema('closure-tool');
    $arrayTool = createTestToolSchema('array-tool');

    $this->registry->registerTool($closureTool, $closure, true);
    $this->registry->registerTool($arrayTool, $arrayHandler, false);

    $expectedCachedData = [
        'tools' => ['array-tool' => json_encode(RegisteredTool::make($arrayTool, $arrayHandler, false))],
        'resources' => [],
        'prompts' => [],
        'resourceTemplates' => [],
    ];

    $this->cache->shouldReceive('set')->once()
        ->with(DISCOVERED_CACHE_KEY_REG, $expectedCachedData)
        ->andReturn(true);

    $result = $this->registry->save();
    expect($result)->toBeTrue();
});

it('handles static method handlers correctly', function () {
    $toolSchema = createTestToolSchema('static-tool');
    $staticHandler = [TestStaticHandler::class, 'handle'];

    $this->registry->registerTool($toolSchema, $staticHandler, true);

    $registeredTool = $this->registry->getTool('static-tool');
    expect($registeredTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($registeredTool->handler)->toBe($staticHandler);
});

it('handles invokable class string handlers correctly', function () {
    $toolSchema = createTestToolSchema('invokable-tool');
    $invokableHandler = TestInvokableHandler::class;

    $this->registry->registerTool($toolSchema, $invokableHandler, true);

    $registeredTool = $this->registry->getTool('invokable-tool');
    expect($registeredTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($registeredTool->handler)->toBe($invokableHandler);
});

// Test helper classes
class TestStaticHandler
{
    public static function handle(): string
    {
        return 'static result';
    }
}

class TestInvokableHandler
{
    public function __invoke(): string
    {
        return 'invokable result';
    }
}
