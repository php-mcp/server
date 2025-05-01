<?php

namespace PhpMcp\Server\Tests;

use LogicException;
use Mockery;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Processor;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Server;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Support\Discoverer;
use PhpMcp\Server\Tests\Mocks\ManualRegistrationStubs\HandlerStub;
use PhpMcp\Server\Tests\Mocks\ManualRegistrationStubs\InvokableHandlerStub;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;

beforeEach(function () {
    // Mock dependencies
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->cache = Mockery::mock(CacheInterface::class)->shouldIgnoreMissing();
    $this->config = Mockery::mock(ConfigurationRepositoryInterface::class);
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->registry = Mockery::mock(Registry::class);
    $this->discoverer = Mockery::mock(Discoverer::class);
    $this->transportState = Mockery::mock(TransportState::class);
    $this->processor = Mockery::mock(Processor::class);

    $this->config->allows('get')->with('mcp.cache.prefix', Mockery::type('string'))->andReturn('mcp_');
    $this->config->allows('get')->with('mcp.cache.ttl', Mockery::type('int'))->andReturn(3600);

    $this->container->allows('get')->with(LoggerInterface::class)->andReturn($this->logger);
    $this->container->allows('get')->with(CacheInterface::class)->andReturn($this->cache);
    $this->container->allows('get')->with(ConfigurationRepositoryInterface::class)->andReturn($this->config);

    // Setup test path
    $this->basePath = sys_get_temp_dir().'/mcp-server-test';
    if (! is_dir($this->basePath)) {
        mkdir($this->basePath, 0777, true);
    }
});

afterEach(function () {
    Mockery::close();

    // Clean up test directory
    if (is_dir($this->basePath)) {
        $files = glob($this->basePath.'/*');
        foreach ($files as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->basePath);
    }
});

// --- Basic Instantiation Tests ---

test('it can be instantiated directly', function () {
    $server = new Server;
    expect($server)->toBeInstanceOf(Server::class);
});

test('it can be instantiated using static factory method', function () {
    $server = Server::make();
    expect($server)->toBeInstanceOf(Server::class);
});

// --- Fluent Configuration Tests ---

test('it can be configured with a custom container', function () {
    $server = new Server;
    $result = $server->withContainer($this->container);

    expect($result)->toBe($server); // Fluent interface returns self
    expect($server->getContainer())->toBe($this->container);
});

// --- Initialization Tests ---

// test('it registers core services to BasicContainer', function () {
//     $container = Mockery::mock(BasicContainer::class);
//     $container->shouldReceive('set')->times(4)->withAnyArgs();

//     $server = new Server;
//     $server->withContainer($container);

//     // Force initialization
//     $server->getProcessor();

//     // With shouldReceive above we're just verifying it was called 4 times
//     expect(true)->toBeTrue();
// });

// --- Run Tests ---

test('it throws exception for unsupported transport', function () {
    $server = new Server;

    expect(fn () => $server->run('unsupported'))->toThrow(LogicException::class, 'Unsupported transport: unsupported');
});

test('it throws exception when trying to run HTTP transport directly', function () {
    $server = new Server;

    expect(fn () => $server->run('http'))->toThrow(LogicException::class, 'Cannot run HTTP transport directly');
});

// --- Component Getter Tests ---

test('it returns the processor instance', function () {
    $server = new Server;
    $processor = $server->getProcessor();
    expect($processor)->toBeInstanceOf(Processor::class);
});

test('it returns the registry instance', function () {
    $server = new Server;
    $registry = $server->getRegistry();
    expect($registry)->toBeInstanceOf(Registry::class);
});

test('it returns the container instance', function () {
    $server = new Server;
    $container = $server->getContainer();
    expect($container)->toBeInstanceOf(ContainerInterface::class);
});

// --- Manual Registration Tests ---

test('it can manually register a tool using array handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerTool')
        ->once()
        ->with(Mockery::on(function (ToolDefinition $def) {
            return $def->getName() === 'customTool'
                && $def->getDescription() === 'Custom Description';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withTool([HandlerStub::class, 'toolHandler'], 'customTool', 'Custom Description');

    expect($result)->toBe($server);
});

test('it can manually register a tool using invokable handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerTool')
        ->once()
        ->with(Mockery::on(function (ToolDefinition $def) {
            return $def->getName() === 'InvokableHandlerStub';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withTool(InvokableHandlerStub::class);

    expect($result)->toBe($server);
});

test('it can manually register a resource using array handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerResource')
        ->once()
        ->with(Mockery::on(function (ResourceDefinition $def) {
            return $def->getName() === 'customResource'
                && $def->getUri() === 'my://resource';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withResource([HandlerStub::class, 'resourceHandler'], 'my://resource', 'customResource');

    expect($result)->toBe($server);
});

test('it can manually register a resource using invokable handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerResource')
        ->once()
        ->with(Mockery::on(function (ResourceDefinition $def) {
            return $def->getName() === 'InvokableHandlerStub'
                && $def->getUri() === 'invokable://resource';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withResource(InvokableHandlerStub::class, 'invokable://resource');

    expect($result)->toBe($server);
});

test('it can manually register a prompt using array handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerPrompt')
        ->once()
        ->with(Mockery::on(function (PromptDefinition $def) {
            return $def->getName() === 'customPrompt';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withPrompt([HandlerStub::class, 'promptHandler'], 'customPrompt');

    expect($result)->toBe($server);
});

test('it can manually register a prompt using invokable handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerPrompt')
        ->once()
        ->with(Mockery::on(function (PromptDefinition $def) {
            return $def->getName() === 'InvokableHandlerStub';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withPrompt(InvokableHandlerStub::class);

    expect($result)->toBe($server);
});

test('it can manually register a resource template using array handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerResourceTemplate')
        ->once()
        ->with(Mockery::on(function (ResourceTemplateDefinition $def) {
            return $def->getName() === 'customTemplate'
                && $def->getUriTemplate() === 'my://template/{id}';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withResourceTemplate([HandlerStub::class, 'templateHandler'], 'customTemplate', null, 'my://template/{id}');

    expect($result)->toBe($server);
});

test('it can manually register a resource template using invokable handler', function () {
    $server = Server::make();
    $server->withContainer($this->container);
    $server->withLogger($this->logger);

    $this->registry->shouldReceive('registerResourceTemplate')
        ->once()
        ->with(Mockery::on(function (ResourceTemplateDefinition $def) {
            return $def->getName() === 'InvokableHandlerStub'
                && $def->getUriTemplate() === 'invokable://template/{id}';
        }));

    $serverReflection = new ReflectionClass($server);
    $registryProperty = $serverReflection->getProperty('registry');
    $registryProperty->setAccessible(true);
    $registryProperty->setValue($server, $this->registry);

    $result = $server->withResourceTemplate(InvokableHandlerStub::class, null, null, 'invokable://template/{id}');

    expect($result)->toBe($server);
});
