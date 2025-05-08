<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Defaults\FileCache;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\Model\Capabilities;
use PhpMcp\Server\Server;
use PhpMcp\Server\ServerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\LoopInterface;
use ReflectionClass;

class DummyHandlerClass
{
    public function handle()
    {
    }
}
class DummyInvokableClass
{
    public function __invoke()
    {
    }
}
class HandlerWithDeps
{
    public function __construct(public LoggerInterface $log)
    {
    }

    #[McpTool(name: 'depTool')]
    public function run()
    {
    }
}

beforeEach(function () {
    $this->builder = new ServerBuilder();

    $this->tempBasePath = sys_get_temp_dir().'/mcp_builder_test_'.bin2hex(random_bytes(4));
    if (! is_dir($this->tempBasePath)) {
        @mkdir($this->tempBasePath, 0777, true);
    }

    $this->tempCachePath = dirname(__DIR__, 3).'/cache';
    if (! is_dir($this->tempCachePath)) {
        @mkdir($this->tempCachePath, 0777, true);
    }
});

afterEach(function () {
    if (! empty($this->tempBasePath) && is_dir($this->tempBasePath)) {
        @rmdir($this->tempBasePath);
    }
    if (! empty($this->tempCachePath) && is_dir($this->tempCachePath)) {
        $cacheFiles = glob($this->tempCachePath.'/mcp_server_registry*.cache');
        if ($cacheFiles) {
            foreach ($cacheFiles as $file) {
                @unlink($file);
            }
        }
    }
    Mockery::close();
});

afterAll(function () {
    if (! empty($this->tempBasePath) && is_dir($this->tempBasePath)) {
        @rmdir($this->tempBasePath);
    }
});

function getBuilderProperty(ServerBuilder $builder, string $propertyName)
{
    $reflector = new ReflectionClass($builder);
    $property = $reflector->getProperty($propertyName);
    $property->setAccessible(true);

    return $property->getValue($builder);
}

// --- Configuration Method Tests ---

it('sets server info', function () {
    $this->builder->withServerInfo('MyServer', '1.2.3');
    expect(getBuilderProperty($this->builder, 'name'))->toBe('MyServer');
    expect(getBuilderProperty($this->builder, 'version'))->toBe('1.2.3');
});

it('sets capabilities', function () {
    $capabilities = Capabilities::forServer(); // Use static factory
    $this->builder->withCapabilities($capabilities);
    expect(getBuilderProperty($this->builder, 'capabilities'))->toBe($capabilities);
});

it('sets logger', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $this->builder->withLogger($logger);
    expect(getBuilderProperty($this->builder, 'logger'))->toBe($logger);
});

it('sets cache and TTL', function () {
    $cache = Mockery::mock(CacheInterface::class);
    $this->builder->withCache($cache, 1800);
    expect(getBuilderProperty($this->builder, 'cache'))->toBe($cache);
    expect(getBuilderProperty($this->builder, 'definitionCacheTtl'))->toBe(1800);
});

it('sets cache with default TTL', function () {
    $cache = Mockery::mock(CacheInterface::class);
    $this->builder->withCache($cache); // No TTL provided
    expect(getBuilderProperty($this->builder, 'cache'))->toBe($cache);
    expect(getBuilderProperty($this->builder, 'definitionCacheTtl'))->toBe(3600); // Default
});

it('sets container', function () {
    $container = Mockery::mock(ContainerInterface::class);
    $this->builder->withContainer($container);
    expect(getBuilderProperty($this->builder, 'container'))->toBe($container);
});

it('sets loop', function () {
    $loop = Mockery::mock(LoopInterface::class);
    $this->builder->withLoop($loop);
    expect(getBuilderProperty($this->builder, 'loop'))->toBe($loop);
});

// --- Manual Registration Storage Tests ---

it('stores manual tool registration data', function () {
    $handler = [DummyHandlerClass::class, 'handle'];
    $name = 'my-tool';
    $desc = 'Tool desc';
    $this->builder->withTool($handler, $name, $desc);

    $manualTools = getBuilderProperty($this->builder, 'manualTools');
    expect($manualTools)->toBeArray()->toHaveCount(1);
    expect($manualTools[0])->toBe(['handler' => $handler, 'name' => $name, 'description' => $desc]);
});

it('stores manual resource registration data', function () {
    $handler = DummyInvokableClass::class;
    $uri = 'test://resource';
    $name = 'inv-res';
    $this->builder->withResource($handler, $uri, $name);

    $manualResources = getBuilderProperty($this->builder, 'manualResources');
    expect($manualResources)->toBeArray()->toHaveCount(1);
    expect($manualResources[0]['handler'])->toBe($handler);
    expect($manualResources[0]['uri'])->toBe($uri);
    expect($manualResources[0]['name'])->toBe($name);
});

it('stores manual resource template registration data', function () {
    $handler = [DummyHandlerClass::class, 'handle'];
    $uriTemplate = 'test://tmpl/{id}';
    $this->builder->withResourceTemplate($handler, $uriTemplate);

    $manualTemplates = getBuilderProperty($this->builder, 'manualResourceTemplates');
    expect($manualTemplates)->toBeArray()->toHaveCount(1);
    expect($manualTemplates[0]['handler'])->toBe($handler);
    expect($manualTemplates[0]['uriTemplate'])->toBe($uriTemplate);
});

it('stores manual prompt registration data', function () {
    $handler = [DummyHandlerClass::class, 'handle'];
    $name = 'my-prompt';
    $this->builder->withPrompt($handler, $name);

    $manualPrompts = getBuilderProperty($this->builder, 'manualPrompts');
    expect($manualPrompts)->toBeArray()->toHaveCount(1);
    expect($manualPrompts[0]['handler'])->toBe($handler);
    expect($manualPrompts[0]['name'])->toBe($name);
});

// --- Build Method Validation Tests ---

it('throws exception if build called without server info', function () {
    $this->builder
        // ->withDiscoveryPaths($this->tempBasePath) // No longer needed
        ->withTool([DummyHandlerClass::class, 'handle']) // Provide manual element
        ->build();
})->throws(ConfigurationException::class, 'Server name and version must be provided');

it('throws exception for empty server name or version', function ($name, $version) {
    $this->builder
        ->withServerInfo($name, $version)
        ->withTool([DummyHandlerClass::class, 'handle']) // Provide manual element
        ->build();
})->throws(ConfigurationException::class, 'Server name and version must be provided')
    ->with([
        ['', '1.0'],
        ['Server', ''],
        [' ', '1.0'],
    ]);

// --- Default Dependency Resolution Tests ---

test('build resolves default Logger correctly', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool([DummyHandlerClass::class, 'handle'])
        ->build();
    expect($server->getConfiguration()->logger)->toBeInstanceOf(NullLogger::class);
});

test('build resolves default Loop correctly', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool([DummyHandlerClass::class, 'handle'])
        ->build();
    expect($server->getConfiguration()->loop)->toBeInstanceOf(LoopInterface::class);
});

test('build resolves default Container correctly', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool([DummyHandlerClass::class, 'handle'])
        ->build();
    expect($server->getConfiguration()->container)->toBeInstanceOf(BasicContainer::class);
});

it('resolves Cache to null if default directory not writable', function () {
    $unwritableDir = '/path/to/non/writable/dir_'.uniqid();
    // Need to ensure the internal default path logic points somewhere bad,
    // or mock the is_writable checks - which is hard.
    // Let's test the outcome: logger warning and null cache in config.
    $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $logger->shouldReceive('warning')
        ->with(Mockery::pattern('/Default cache directory not found or not writable|Failed to initialize default FileCache/'), Mockery::any())
        ->once();
    // We can't easily *force* the default path to be unwritable without modifying the code under test,
    // so we rely on the fact that if the `new FileCache` fails or the dir check fails,
    // the builder will log a warning and proceed with null cache.
    // This test mainly verifies the builder *calls* the logger on failure.

    $builder = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withLogger($logger) // Inject mock logger
        ->withTool([DummyHandlerClass::class, 'handle']);

    // Manually set the internal cache to null *before* build to simulate failed default creation path
    $reflector = new ReflectionClass($builder);
    $cacheProp = $reflector->getProperty('cache');
    $cacheProp->setAccessible(true);
    $cacheProp->setValue($builder, null);
    // Force internal logic path by temporarily making default dir unwritable if possible
    $originalPerms = fileperms($this->tempCachePath);
    @chmod($this->tempCachePath, 0444); // Try making read-only

    $server = $builder->build();

    @chmod($this->tempCachePath, $originalPerms); // Restore permissions

    // We expect the logger warning was triggered and cache is null
    expect($server->getConfiguration()->cache)->toBeNull();
});

test('build uses provided dependencies over defaults', function () {
    $myLoop = Mockery::mock(LoopInterface::class);
    $myLogger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $myContainer = Mockery::mock(ContainerInterface::class);
    $myCache = Mockery::mock(CacheInterface::class);
    $myCaps = Capabilities::forServer(resourcesSubscribe: true);

    $server = $this->builder
        ->withServerInfo('CustomDeps', '1.0')
        ->withLoop($myLoop)
        ->withLogger($myLogger)
        ->withContainer($myContainer)
        ->withCache($myCache)
        ->withCapabilities($myCaps)
        ->withTool([DummyHandlerClass::class, 'handle']) // Add element
        ->build();

    $config = $server->getConfiguration();
    expect($config->loop)->toBe($myLoop);
    expect($config->logger)->toBe($myLogger);
    expect($config->container)->toBe($myContainer);
    expect($config->cache)->toBe($myCache);
    expect($config->capabilities)->toBe($myCaps);
});

// --- Tests for build() success and manual registration ---

it('build successfully creates Server with defaults', function () {
    $container = new BasicContainer();
    $container->set(LoggerInterface::class, new NullLogger());

    $server = $this->builder
        ->withServerInfo('BuiltServer', '1.0')
        ->withContainer($container)
        ->withTool([DummyHandlerClass::class, 'handle'], 'manualTool')
        ->build();

    expect($server)->toBeInstanceOf(Server::class);
    $config = $server->getConfiguration();
    expect($config->serverName)->toBe('BuiltServer');
    expect($server->getRegistry()->findTool('manualTool'))->not->toBeNull();
    expect($config->logger)->toBeInstanceOf(NullLogger::class);
    expect($config->loop)->toBeInstanceOf(LoopInterface::class);
    expect($config->container)->toBe($container);
    expect($config->capabilities)->toBeInstanceOf(Capabilities::class);

}); // REMOVED skip

it('build successfully creates Server with custom dependencies', function () {
    $myLoop = Mockery::mock(LoopInterface::class);
    $myLogger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $myContainer = Mockery::mock(ContainerInterface::class);
    $myCache = Mockery::mock(CacheInterface::class);
    $myCaps = Capabilities::forServer(resourcesSubscribe: true);

    $server = $this->builder
        ->withServerInfo('CustomServer', '2.0')
        ->withLoop($myLoop)->withLogger($myLogger)->withContainer($myContainer)
        ->withCache($myCache)->withCapabilities($myCaps)
        ->withPrompt(DummyInvokableClass::class) // Add one element
        ->build();

    expect($server)->toBeInstanceOf(Server::class);
    $config = $server->getConfiguration();
    expect($config->serverName)->toBe('CustomServer');
    expect($config->logger)->toBe($myLogger);
    expect($config->loop)->toBe($myLoop);
    expect($config->container)->toBe($myContainer);
    expect($config->cache)->toBe($myCache);
    expect($config->capabilities)->toBe($myCaps);
    expect($server->getRegistry()->allPrompts()->count())->toBe(1);

}); // REMOVED skip

it('build throws DefinitionException if manual tool registration fails', function () {
    $container = new BasicContainer();
    $container->set(LoggerInterface::class, new NullLogger());

    $this->builder
        ->withServerInfo('FailRegServer', '1.0')
        ->withContainer($container)
        // Use a method that doesn't exist on the mock class
        ->withTool([DummyHandlerClass::class, 'nonExistentMethod'], 'badTool')
        ->build();

})->throws(DefinitionException::class, '1 error(s) occurred during manual element registration');

it('build throws DefinitionException if manual resource registration fails', function () {
    $container = new BasicContainer();
    $container->set(LoggerInterface::class, new NullLogger());

    $this->builder
        ->withServerInfo('FailRegServer', '1.0')
        ->withContainer($container)
        ->withResource([DummyHandlerClass::class, 'handle'], 'invalid-uri-no-scheme') // Invalid URI
        ->build();

})->throws(DefinitionException::class, '1 error(s) occurred during manual element registration');
