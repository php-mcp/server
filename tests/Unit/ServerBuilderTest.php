<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Attributes\CompletionProvider;
use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Elements\RegisteredPrompt;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Server;
use PhpMcp\Server\ServerBuilder;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\CacheSessionHandler;
use PhpMcp\Server\Session\SessionManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use ReflectionClass;

class SB_DummyHandlerClass
{
    public function handle(string $arg): string
    {
        return "handled: {$arg}";
    }

    public function noArgsHandler(): string
    {
        return "no-args";
    }

    public function handlerWithCompletion(
        string $name,
        #[CompletionProvider(provider: SB_DummyCompletionProvider::class)]
        string $uriParam
    ): array {
        return [];
    }
}

class SB_DummyInvokableClass
{
    public function __invoke(int $id): array
    {
        return ['id' => $id];
    }
}

class SB_DummyCompletionProvider implements CompletionProviderInterface
{
    public function getCompletions(string $currentValue, SessionInterface $session): array
    {
        return [];
    }
}


beforeEach(function () {
    $this->builder = new ServerBuilder();
});


it('sets server info correctly', function () {
    $this->builder->withServerInfo('MyServer', '1.2.3');
    $serverInfo = getPrivateProperty($this->builder, 'serverInfo');
    expect($serverInfo)->toBeInstanceOf(Implementation::class)
        ->and($serverInfo->name)->toBe('MyServer')
        ->and($serverInfo->version)->toBe('1.2.3');
});

it('sets capabilities correctly', function () {
    $capabilities = ServerCapabilities::make(toolsListChanged: true);
    $this->builder->withCapabilities($capabilities);
    expect(getPrivateProperty($this->builder, 'capabilities'))->toBe($capabilities);
});

it('sets pagination limit correctly', function () {
    $this->builder->withPaginationLimit(100);
    expect(getPrivateProperty($this->builder, 'paginationLimit'))->toBe(100);
});

it('sets logger correctly', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $this->builder->withLogger($logger);
    expect(getPrivateProperty($this->builder, 'logger'))->toBe($logger);
});

it('sets cache correctly', function () {
    $cache = Mockery::mock(CacheInterface::class);
    $this->builder->withCache($cache);
    expect(getPrivateProperty($this->builder, 'cache'))->toBe($cache);
});

it('sets session handler correctly', function () {
    $handler = Mockery::mock(SessionHandlerInterface::class);
    $this->builder->withSessionHandler($handler, 7200);
    expect(getPrivateProperty($this->builder, 'sessionHandler'))->toBe($handler);
    expect(getPrivateProperty($this->builder, 'sessionTtl'))->toBe(7200);
});

it('sets session driver to array correctly', function () {
    $this->builder->withSession('array', 1800);
    expect(getPrivateProperty($this->builder, 'sessionDriver'))->toBe('array');
    expect(getPrivateProperty($this->builder, 'sessionTtl'))->toBe(1800);
});

it('sets session driver to cache correctly', function () {
    $this->builder->withSession('cache', 900);
    expect(getPrivateProperty($this->builder, 'sessionDriver'))->toBe('cache');
    expect(getPrivateProperty($this->builder, 'sessionTtl'))->toBe(900);
});

it('uses default TTL when not specified for session', function () {
    $this->builder->withSession('array');
    expect(getPrivateProperty($this->builder, 'sessionTtl'))->toBe(3600);
});

it('throws exception for invalid session driver', function () {
    $this->builder->withSession('redis');
})->throws(\InvalidArgumentException::class, "Unsupported session driver 'redis'. Only 'array' and 'cache' drivers are supported.");

it('throws exception for cache session driver without cache during build', function () {
    $this->builder
        ->withServerInfo('Test', '1.0')
        ->withSession('cache')
        ->build();
})->throws(ConfigurationException::class, 'Cache session driver requires a cache instance');

it('creates ArraySessionHandler when array driver is specified', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withSession('array', 1800)
        ->build();

    $sessionManager = $server->getSessionManager();
    $smReflection = new ReflectionClass(SessionManager::class);
    $handlerProp = $smReflection->getProperty('handler');
    $handlerProp->setAccessible(true);
    $handler = $handlerProp->getValue($sessionManager);

    expect($handler)->toBeInstanceOf(ArraySessionHandler::class);
    expect($handler->ttl)->toBe(1800);
});

it('creates CacheSessionHandler when cache driver is specified', function () {
    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('get')->with('mcp_session_index', [])->andReturn([]);

    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withCache($cache)
        ->withSession('cache', 900)
        ->build();

    $sessionManager = $server->getSessionManager();
    $smReflection = new ReflectionClass(SessionManager::class);
    $handlerProp = $smReflection->getProperty('handler');
    $handlerProp->setAccessible(true);
    $handler = $handlerProp->getValue($sessionManager);

    expect($handler)->toBeInstanceOf(CacheSessionHandler::class);
    expect($handler->cache)->toBe($cache);
    expect($handler->ttl)->toBe(900);
});

it('prefers custom session handler over session driver', function () {
    $customHandler = Mockery::mock(SessionHandlerInterface::class);

    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withSession('array')
        ->withSessionHandler($customHandler, 1200)
        ->build();

    $sessionManager = $server->getSessionManager();
    $smReflection = new ReflectionClass(SessionManager::class);
    $handlerProp = $smReflection->getProperty('handler');
    $handlerProp->setAccessible(true);

    expect($handlerProp->getValue($sessionManager))->toBe($customHandler);
});


it('sets container correctly', function () {
    $container = Mockery::mock(ContainerInterface::class);
    $this->builder->withContainer($container);
    expect(getPrivateProperty($this->builder, 'container'))->toBe($container);
});

it('sets loop correctly', function () {
    $loop = Mockery::mock(LoopInterface::class);
    $this->builder->withLoop($loop);
    expect(getPrivateProperty($this->builder, 'loop'))->toBe($loop);
});

it('stores manual tool registration data', function () {
    $handler = [SB_DummyHandlerClass::class, 'handle'];
    $this->builder->withTool($handler, 'my-tool', 'Tool desc');
    $manualTools = getPrivateProperty($this->builder, 'manualTools');
    expect($manualTools[0]['handler'])->toBe($handler)
        ->and($manualTools[0]['name'])->toBe('my-tool')
        ->and($manualTools[0]['description'])->toBe('Tool desc');
});

it('stores manual resource registration data', function () {
    $handler = [SB_DummyHandlerClass::class, 'handle'];
    $this->builder->withResource($handler, 'res://resource', 'Resource name');
    $manualResources = getPrivateProperty($this->builder, 'manualResources');
    expect($manualResources[0]['handler'])->toBe($handler)
        ->and($manualResources[0]['uri'])->toBe('res://resource')
        ->and($manualResources[0]['name'])->toBe('Resource name');
});

it('stores manual resource template registration data', function () {
    $handler = [SB_DummyHandlerClass::class, 'handle'];
    $this->builder->withResourceTemplate($handler, 'res://resource', 'Resource name');
    $manualResourceTemplates = getPrivateProperty($this->builder, 'manualResourceTemplates');
    expect($manualResourceTemplates[0]['handler'])->toBe($handler)
        ->and($manualResourceTemplates[0]['uriTemplate'])->toBe('res://resource')
        ->and($manualResourceTemplates[0]['name'])->toBe('Resource name');
});

it('stores manual prompt registration data', function () {
    $handler = [SB_DummyHandlerClass::class, 'handle'];
    $this->builder->withPrompt($handler, 'my-prompt', 'Prompt desc');
    $manualPrompts = getPrivateProperty($this->builder, 'manualPrompts');
    expect($manualPrompts[0]['handler'])->toBe($handler)
        ->and($manualPrompts[0]['name'])->toBe('my-prompt')
        ->and($manualPrompts[0]['description'])->toBe('Prompt desc');
});

it('throws ConfigurationException if server info not provided', function () {
    $this->builder->build();
})->throws(ConfigurationException::class, 'Server name and version must be provided');


it('resolves default Logger, Loop, Container, SessionHandler if not provided', function () {
    $server = $this->builder->withServerInfo('Test', '1.0')->build();
    $config = $server->getConfiguration();

    expect($config->logger)->toBeInstanceOf(NullLogger::class);
    expect($config->loop)->toBeInstanceOf(LoopInterface::class);
    expect($config->container)->toBeInstanceOf(BasicContainer::class);

    $sessionManager = $server->getSessionManager();
    $smReflection = new ReflectionClass(SessionManager::class);
    $handlerProp = $smReflection->getProperty('handler');
    $handlerProp->setAccessible(true);
    expect($handlerProp->getValue($sessionManager))->toBeInstanceOf(ArraySessionHandler::class);
});

it('builds Server with correct Configuration, Registry, Protocol, SessionManager', function () {
    $logger = new NullLogger();
    $loop = Mockery::mock(LoopInterface::class)->shouldIgnoreMissing();
    $cache = Mockery::mock(CacheInterface::class);
    $container = Mockery::mock(ContainerInterface::class);
    $sessionHandler = Mockery::mock(SessionHandlerInterface::class);
    $capabilities = ServerCapabilities::make(promptsListChanged: true, resourcesListChanged: true);

    $loop->shouldReceive('addPeriodicTimer')->with(300, Mockery::type('callable'))->andReturn(Mockery::mock(TimerInterface::class));

    $server = $this->builder
        ->withServerInfo('FullBuild', '3.0')
        ->withLogger($logger)
        ->withLoop($loop)
        ->withCache($cache)
        ->withContainer($container)
        ->withSessionHandler($sessionHandler)
        ->withCapabilities($capabilities)
        ->withPaginationLimit(75)
        ->build();

    expect($server)->toBeInstanceOf(Server::class);

    $config = $server->getConfiguration();
    expect($config->serverInfo->name)->toBe('FullBuild');
    expect($config->serverInfo->version)->toBe('3.0');
    expect($config->capabilities)->toBe($capabilities);
    expect($config->logger)->toBe($logger);
    expect($config->loop)->toBe($loop);
    expect($config->cache)->toBe($cache);
    expect($config->container)->toBe($container);
    expect($config->paginationLimit)->toBe(75);

    expect($server->getRegistry())->toBeInstanceOf(Registry::class);
    expect($server->getProtocol())->toBeInstanceOf(Protocol::class);
    expect($server->getSessionManager())->toBeInstanceOf(SessionManager::class);
    $smReflection = new ReflectionClass($server->getSessionManager());
    $handlerProp = $smReflection->getProperty('handler');
    $handlerProp->setAccessible(true);
    expect($handlerProp->getValue($server->getSessionManager()))->toBe($sessionHandler);
});

it('registers manual tool successfully during build', function () {
    $handler = [SB_DummyHandlerClass::class, 'handle'];

    $server = $this->builder
        ->withServerInfo('ManualToolTest', '1.0')
        ->withTool($handler, 'test-manual-tool', 'A test tool')
        ->build();

    $registry = $server->getRegistry();
    $tool = $registry->getTool('test-manual-tool');

    expect($tool)->toBeInstanceOf(RegisteredTool::class);
    expect($tool->isManual)->toBeTrue();
    expect($tool->schema->name)->toBe('test-manual-tool');
    expect($tool->schema->description)->toBe('A test tool');
    expect($tool->schema->inputSchema)->toEqual(['type' => 'object', 'properties' => ['arg' => ['type' => 'string']], 'required' => ['arg']]);
    expect($tool->handler)->toBe($handler);
});

it('infers tool name from invokable class if not provided', function () {
    $handler = SB_DummyInvokableClass::class;

    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool($handler)
        ->build();

    $tool = $server->getRegistry()->getTool('SB_DummyInvokableClass');
    expect($tool)->not->toBeNull();
    expect($tool->schema->name)->toBe('SB_DummyInvokableClass');
});

it('registers tool with closure handler', function () {
    $closure = function (string $message): string {
        return "Hello, $message!";
    };

    $server = $this->builder
        ->withServerInfo('ClosureTest', '1.0')
        ->withTool($closure, 'greet-tool', 'A greeting tool')
        ->build();

    $tool = $server->getRegistry()->getTool('greet-tool');
    expect($tool)->toBeInstanceOf(RegisteredTool::class);
    expect($tool->isManual)->toBeTrue();
    expect($tool->schema->name)->toBe('greet-tool');
    expect($tool->schema->description)->toBe('A greeting tool');
    expect($tool->handler)->toBe($closure);
    expect($tool->schema->inputSchema)->toEqual([
        'type' => 'object',
        'properties' => ['message' => ['type' => 'string']],
        'required' => ['message']
    ]);
});

it('registers tool with static method handler', function () {
    $handler = [SB_DummyHandlerClass::class, 'handle'];

    $server = $this->builder
        ->withServerInfo('StaticTest', '1.0')
        ->withTool($handler, 'static-tool', 'A static method tool')
        ->build();

    $tool = $server->getRegistry()->getTool('static-tool');
    expect($tool)->toBeInstanceOf(RegisteredTool::class);
    expect($tool->isManual)->toBeTrue();
    expect($tool->schema->name)->toBe('static-tool');
    expect($tool->handler)->toBe($handler);
});

it('registers resource with closure handler', function () {
    $closure = function (string $id): array {
        return [
            'uri' => "res://item/$id",
            'name' => "Item $id",
            'mimeType' => 'application/json'
        ];
    };

    $server = $this->builder
        ->withServerInfo('ResourceTest', '1.0')
        ->withResource($closure, 'res://items/{id}', 'dynamic_resource')
        ->build();

    $resource = $server->getRegistry()->getResource('res://items/{id}');
    expect($resource)->not->toBeNull();
    expect($resource->handler)->toBe($closure);
    expect($resource->isManual)->toBeTrue();
});

it('registers prompt with closure handler', function () {
    $closure = function (string $topic): array {
        return [
            'role' => 'user',
            'content' => ['type' => 'text', 'text' => "Tell me about $topic"]
        ];
    };

    $server = $this->builder
        ->withServerInfo('PromptTest', '1.0')
        ->withPrompt($closure, 'topic-prompt', 'A topic-based prompt')
        ->build();

    $prompt = $server->getRegistry()->getPrompt('topic-prompt');
    expect($prompt)->not->toBeNull();
    expect($prompt->handler)->toBe($closure);
    expect($prompt->isManual)->toBeTrue();
});

it('infers closure tool name automatically', function () {
    $closure = function (int $count): array {
        return ['count' => $count];
    };

    $server = $this->builder
        ->withServerInfo('AutoNameTest', '1.0')
        ->withTool($closure)
        ->build();

    $tools = $server->getRegistry()->getTools();
    expect($tools)->toHaveCount(1);

    $toolName = array_keys($tools)[0];
    expect($toolName)->toStartWith('closure_tool_');

    $tool = $server->getRegistry()->getTool($toolName);
    expect($tool->handler)->toBe($closure);
});

it('generates unique names for multiple closures', function () {
    $closure1 = function (string $a): string {
        return $a;
    };
    $closure2 = function (int $b): int {
        return $b;
    };

    $server = $this->builder
        ->withServerInfo('MultiClosureTest', '1.0')
        ->withTool($closure1)
        ->withTool($closure2)
        ->build();

    $tools = $server->getRegistry()->getTools();
    expect($tools)->toHaveCount(2);

    $toolNames = array_keys($tools);
    expect($toolNames[0])->toStartWith('closure_tool_');
    expect($toolNames[1])->toStartWith('closure_tool_');
    expect($toolNames[0])->not->toBe($toolNames[1]);
});

it('infers prompt arguments and completion providers for manual prompt', function () {
    $handler = [SB_DummyHandlerClass::class, 'handlerWithCompletion'];

    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withPrompt($handler, 'myPrompt')
        ->build();

    $prompt = $server->getRegistry()->getPrompt('myPrompt');
    expect($prompt)->toBeInstanceOf(RegisteredPrompt::class);
    expect($prompt->schema->arguments)->toHaveCount(2);
    expect($prompt->schema->arguments[0]->name)->toBe('name');
    expect($prompt->schema->arguments[1]->name)->toBe('uriParam');
    expect($prompt->completionProviders['uriParam'])->toBe(SB_DummyCompletionProvider::class);
});

// Add test fixtures for enhanced completion providers
class SB_DummyHandlerWithEnhancedCompletion
{
    public function handleWithListCompletion(
        #[CompletionProvider(values: ['option1', 'option2', 'option3'])]
        string $choice
    ): array {
        return [['role' => 'user', 'content' => "Selected: {$choice}"]];
    }

    public function handleWithEnumCompletion(
        #[CompletionProvider(enum: SB_TestEnum::class)]
        string $status
    ): array {
        return [['role' => 'user', 'content' => "Status: {$status}"]];
    }
}

enum SB_TestEnum: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

it('creates ListCompletionProvider for values attribute in manual registration', function () {
    $handler = [SB_DummyHandlerWithEnhancedCompletion::class, 'handleWithListCompletion'];

    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withPrompt($handler, 'listPrompt')
        ->build();

    $prompt = $server->getRegistry()->getPrompt('listPrompt');
    expect($prompt->completionProviders['choice'])->toBeInstanceOf(\PhpMcp\Server\Defaults\ListCompletionProvider::class);
});

it('creates EnumCompletionProvider for enum attribute in manual registration', function () {
    $handler = [SB_DummyHandlerWithEnhancedCompletion::class, 'handleWithEnumCompletion'];

    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withPrompt($handler, 'enumPrompt')
        ->build();

    $prompt = $server->getRegistry()->getPrompt('enumPrompt');
    expect($prompt->completionProviders['status'])->toBeInstanceOf(\PhpMcp\Server\Defaults\EnumCompletionProvider::class);
});

// it('throws DefinitionException if HandlerResolver fails for a manual element', function () {
//     $handler = ['NonExistentClass', 'method'];

//     $server = $this->builder
//         ->withServerInfo('Test', '1.0')
//         ->withTool($handler, 'badTool')
//         ->build();
// })->throws(DefinitionException::class, '1 error(s) occurred during manual element registration');


it('builds successfully with minimal valid config', function () {
    $server = $this->builder
        ->withServerInfo('TS-Compatible', '0.1')
        ->build();
    expect($server)->toBeInstanceOf(Server::class);
});

it('can be built multiple times with different configurations', function () {
    $builder = new ServerBuilder();

    $server1 = $builder
        ->withServerInfo('ServerOne', '1.0')
        ->withTool([SB_DummyHandlerClass::class, 'handle'], 'toolOne')
        ->build();

    $server2 = $builder
        ->withServerInfo('ServerTwo', '2.0')
        ->withTool([SB_DummyHandlerClass::class, 'noArgsHandler'], 'toolTwo')
        ->build();

    expect($server1->getConfiguration()->serverInfo->name)->toBe('ServerOne');
    $registry1 = $server1->getRegistry();
    expect($registry1->getTool('toolOne'))->not->toBeNull();
    expect($registry1->getTool('toolTwo'))->toBeNull();

    expect($server2->getConfiguration()->serverInfo->name)->toBe('ServerTwo');
    $registry2 = $server2->getRegistry();
    expect($registry2->getTool('toolOne'))->not->toBeNull();
    expect($registry2->getTool('toolTwo'))->not->toBeNull();

    $builder3 = new ServerBuilder();
    $server3 = $builder3->withServerInfo('ServerThree', '3.0')->build();
    expect($server3->getRegistry()->hasElements())->toBeFalse();
});
