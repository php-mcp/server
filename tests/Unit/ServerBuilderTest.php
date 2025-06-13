<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Defaults\BasicContainer;
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
    public function handle() {}
}
class DummyInvokableClass
{
    public function __invoke() {}
}
class HandlerWithDeps
{
    public function __construct(public LoggerInterface $log) {}

    #[McpTool(name: 'depTool')]
    public function run() {}
}

beforeEach(function () {
    $this->builder = new ServerBuilder();
});

function getBuilderProperty(ServerBuilder $builder, string $propertyName)
{
    $reflector = new ReflectionClass($builder);
    $property = $reflector->getProperty($propertyName);
    $property->setAccessible(true);

    return $property->getValue($builder);
}

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

it('stores manual tool registration data', function () {
    $handler = [DummyHandlerClass::class, 'handle'];
    $name = 'my-tool';
    $desc = 'Tool desc';
    $this->builder->withTool($handler, $name, $desc);

    $manualTools = getBuilderProperty($this->builder, 'manualTools');
    expect($manualTools)->toBeArray()->toHaveCount(1);
    expect($manualTools[0])->toBe(['handler' => $handler, 'name' => $name, 'description' => $desc, 'annotations' => []]);
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

it('resolves default Logger correctly when building', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool([DummyHandlerClass::class, 'handle'])
        ->build();
    expect($server->getConfiguration()->logger)->toBeInstanceOf(NullLogger::class);
});

it('resolves default Loop correctly when building', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool([DummyHandlerClass::class, 'handle'])
        ->build();
    expect($server->getConfiguration()->loop)->toBeInstanceOf(LoopInterface::class);
});

it('resolves default Container correctly when building', function () {
    $server = $this->builder
        ->withServerInfo('Test', '1.0')
        ->withTool([DummyHandlerClass::class, 'handle'])
        ->build();
    expect($server->getConfiguration()->container)->toBeInstanceOf(BasicContainer::class);
});

it('uses provided dependencies over defaults when building', function () {
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

it('successfully creates Server with defaults', function () {
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
});

it('successfully creates Server with custom dependencies', function () {
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
});

it('throws DefinitionException if manual tool registration fails', function () {
    $container = new BasicContainer();
    $container->set(LoggerInterface::class, new NullLogger());

    $this->builder
        ->withServerInfo('FailRegServer', '1.0')
        ->withContainer($container)
        ->withTool([DummyHandlerClass::class, 'nonExistentMethod'], 'badTool')
        ->build();
})->throws(DefinitionException::class, '1 error(s) occurred during manual element registration');

it('throws DefinitionException if manual resource registration fails', function () {
    $container = new BasicContainer();
    $container->set(LoggerInterface::class, new NullLogger());

    $this->builder
        ->withServerInfo('FailRegServer', '1.0')
        ->withContainer($container)
        ->withResource([DummyHandlerClass::class, 'handle'], 'invalid-uri-no-scheme') // Invalid URI
        ->build();
})->throws(DefinitionException::class, '1 error(s) occurred during manual element registration');
