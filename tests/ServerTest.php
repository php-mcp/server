<?php

namespace PhpMcp\Server\Tests;

use LogicException;
use Mockery;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Processor;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Server;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Support\Discoverer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

beforeEach(function () {
    // Mock dependencies
    $this->mockLogger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->mockCache = Mockery::mock(CacheInterface::class);
    $this->mockConfig = Mockery::mock(ConfigurationRepositoryInterface::class);
    $this->mockContainer = Mockery::mock(ContainerInterface::class);
    $this->mockRegistry = Mockery::mock(Registry::class);
    $this->mockDiscoverer = Mockery::mock(Discoverer::class);
    $this->mockTransportState = Mockery::mock(TransportState::class);
    $this->mockProcessor = Mockery::mock(Processor::class);

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
    $result = $server->withContainer($this->mockContainer);

    expect($result)->toBe($server); // Fluent interface returns self
    expect($server->getContainer())->toBe($this->mockContainer);
});

test('it can be configured with a custom logger', function () {
    $server = new Server;
    $result = $server->withLogger($this->mockLogger);

    expect($result)->toBe($server);
    expect($server->getLogger())->toBe($this->mockLogger);
});

test('it can be configured with a custom cache', function () {
    $this->mockCache->shouldReceive('get')->andReturn(null);

    $server = new Server;
    $result = $server->withCache($this->mockCache);

    expect($result)->toBe($server);
    expect($server->getCache())->toBe($this->mockCache);
});

test('it can be configured with a custom config', function () {
    $this->mockConfig->shouldReceive('get')->withAnyArgs()->andReturnUsing(function ($key, $default = null) {
        if ($key === 'mcp.protocol_versions') {
            return ['2024-11-05'];
        }
        if ($key === 'mcp.cache.prefix') {
            return 'mcp:';
        }
        if ($key === 'mcp.cache.ttl') {
            return 3600;
        }
        if ($key === 'mcp.cache.key') {
            return 'mcp.elements.cache';
        }

        return $default;
    });
    $this->mockConfig->shouldReceive('set')->withAnyArgs()->andReturn(true);

    // Need to mock cache for the TransportState
    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheMock->shouldReceive('get')->withAnyArgs()->andReturn(null);

    $server = new Server;
    $server->withCache($cacheMock);
    $result = $server->withConfig($this->mockConfig);

    expect($result)->toBe($server);
    expect($server->getConfig())->toBe($this->mockConfig);
});

test('it can be configured with a base path', function () {
    $basePath = '/custom/path';

    $configMock = Mockery::mock(ArrayConfigurationRepository::class);
    $configMock->allows('get')->andReturn(null);
    $configMock->shouldReceive('set')->once()->with('mcp.discovery.base_path', $basePath);

    $server = new Server;
    $server->withConfig($configMock);

    $result = $server->withBasePath($basePath);
    expect($result)->toBe($server);
});

test('it can be configured with scan directories', function () {
    $scanDirs = ['src', 'app/MCP'];

    $configMock = Mockery::mock(ArrayConfigurationRepository::class);
    $configMock->allows('get')->andReturn(null);
    $configMock->shouldReceive('set')->once()->with('mcp.discovery.directories', $scanDirs);

    $server = new Server;
    $server->withConfig($configMock);

    $result = $server->withScanDirectories($scanDirs);
    expect($result)->toBe($server);
});

// --- Initialization Tests ---

test('it creates default dependencies when none provided', function () {
    $server = new Server;

    expect($server->getLogger())->toBeInstanceOf(LoggerInterface::class);
    expect($server->getCache())->toBeInstanceOf(CacheInterface::class);
    expect($server->getContainer())->toBeInstanceOf(ContainerInterface::class);
    expect($server->getConfig())->toBeInstanceOf(ConfigurationRepositoryInterface::class);
});

test('it registers core services to BasicContainer', function () {
    $container = Mockery::mock(BasicContainer::class);
    $container->shouldReceive('set')->times(4)->withAnyArgs();

    $server = new Server;
    $server->withContainer($container);

    // Force initialization
    $server->getProcessor();

    // With shouldReceive above we're just verifying it was called 4 times
    expect(true)->toBeTrue();
});

test('it initializes with default configuration values when no config provided', function () {
    $server = new Server;
    $config = $server->getConfig();

    expect($config->get('mcp.server.name'))->toBe('PHP MCP Server');
    expect($config->get('mcp.server.version'))->toBe('1.0.0');
    expect($config->get('mcp.protocol_versions'))->toContain('2024-11-05');
    expect($config->get('mcp.pagination_limit'))->toBe(50);
    expect($config->get('mcp.capabilities.tools.enabled'))->toBeTrue();
    expect($config->get('mcp.capabilities.resources.enabled'))->toBeTrue();
    expect($config->get('mcp.capabilities.prompts.enabled'))->toBeTrue();
    expect($config->get('mcp.capabilities.logging.enabled'))->toBeTrue();
});

test('it applies custom base path and scan directories to config', function () {
    $basePath = '/custom/base/path';
    $scanDirs = ['app', 'src/MCP'];

    $server = new Server;
    $server->withBasePath($basePath);
    $server->withScanDirectories($scanDirs);

    $config = $server->getConfig();

    expect($config->get('mcp.discovery.base_path'))->toBe($basePath);
    expect($config->get('mcp.discovery.directories'))->toBe($scanDirs);
});

// --- Discovery Tests ---

test('it performs discovery using the discoverer', function () {
    $basePath = '/test/path';
    $scanDirs = ['src'];

    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheMock->shouldReceive('get')->withAnyArgs()->andReturn(null);
    $cacheMock->shouldReceive('set')->withAnyArgs()->andReturn(true);
    $cacheMock->shouldReceive('delete')->withAnyArgs()->andReturn(true);

    $configMock = Mockery::mock(ConfigurationRepositoryInterface::class);
    $configMock->shouldReceive('get')->withAnyArgs()->andReturnUsing(function ($key, $default = null) use ($basePath, $scanDirs) {
        if ($key === 'mcp.discovery.base_path') {
            return $basePath;
        }
        if ($key === 'mcp.discovery.directories') {
            return $scanDirs;
        }
        if ($key === 'mcp.protocol_versions') {
            return ['2024-11-05'];
        }
        if ($key === 'mcp.cache.prefix') {
            return 'mcp:';
        }
        if ($key === 'mcp.cache.ttl') {
            return 3600;
        }
        if ($key === 'mcp.cache.key') {
            return 'mcp.elements.cache';
        }

        return $default;
    });
    $configMock->shouldReceive('set')->withAnyArgs()->andReturn(true);

    $registryMock = Mockery::mock(Registry::class);
    $registryMock->shouldReceive('clearCache')->once();
    $registryMock->shouldReceive('cacheElements')->once();
    $registryMock->shouldReceive('loadElements')->zeroOrMoreTimes();

    $discovererMock = Mockery::mock(Discoverer::class);
    $discovererMock->shouldReceive('discover')->once()->with($basePath, $scanDirs);

    // Use reflection to inject mocks
    $server = new Server;
    $server->withCache($cacheMock);  // Set the cache before other dependencies

    $reflection = new \ReflectionClass($server);

    $configProp = $reflection->getProperty('config');
    $configProp->setAccessible(true);
    $configProp->setValue($server, $configMock);

    $registryProp = $reflection->getProperty('registry');
    $registryProp->setAccessible(true);
    $registryProp->setValue($server, $registryMock);

    $discovererProp = $reflection->getProperty('discoverer');
    $discovererProp->setAccessible(true);
    $discovererProp->setValue($server, $discovererMock);

    // Call discover
    $result = $server->discover();

    expect($result)->toBe($server);
});

test('it skips cache clearing when specified in discover method', function () {
    $basePath = '/test/path';
    $scanDirs = ['src'];

    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheMock->shouldReceive('get')->withAnyArgs()->andReturn(null);
    $cacheMock->shouldReceive('set')->withAnyArgs()->andReturn(true);
    $cacheMock->shouldReceive('delete')->withAnyArgs()->andReturn(true);

    $configMock = Mockery::mock(ConfigurationRepositoryInterface::class);
    $configMock->shouldReceive('get')->withAnyArgs()->andReturnUsing(function ($key, $default = null) use ($basePath, $scanDirs) {
        if ($key === 'mcp.discovery.base_path') {
            return $basePath;
        }
        if ($key === 'mcp.discovery.directories') {
            return $scanDirs;
        }
        if ($key === 'mcp.protocol_versions') {
            return ['2024-11-05'];
        }
        if ($key === 'mcp.cache.prefix') {
            return 'mcp:';
        }
        if ($key === 'mcp.cache.ttl') {
            return 3600;
        }
        if ($key === 'mcp.cache.key') {
            return 'mcp.elements.cache';
        }

        return $default;
    });
    $configMock->shouldReceive('set')->withAnyArgs()->andReturn(true);

    $registryMock = Mockery::mock(Registry::class);
    $registryMock->shouldNotReceive('clearCache'); // Should not be called
    $registryMock->shouldReceive('cacheElements')->once();
    $registryMock->shouldReceive('loadElements')->zeroOrMoreTimes();

    $discovererMock = Mockery::mock(Discoverer::class);
    $discovererMock->shouldReceive('discover')->once()->with($basePath, $scanDirs);

    // Use reflection to inject mocks
    $server = new Server;
    $server->withCache($cacheMock);  // Set the cache before other dependencies

    $reflection = new \ReflectionClass($server);

    $configProp = $reflection->getProperty('config');
    $configProp->setAccessible(true);
    $configProp->setValue($server, $configMock);

    $registryProp = $reflection->getProperty('registry');
    $registryProp->setAccessible(true);
    $registryProp->setValue($server, $registryMock);

    $discovererProp = $reflection->getProperty('discoverer');
    $discovererProp->setAccessible(true);
    $discovererProp->setValue($server, $discovererMock);

    // Call discover with false to skip cache clearing
    $result = $server->discover(false);

    expect($result)->toBe($server);
});

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

test('it returns the state manager instance', function () {
    $server = new Server;
    $stateManager = $server->getStateManager();
    expect($stateManager)->toBeInstanceOf(TransportState::class);
});

test('it returns the config instance', function () {
    $server = new Server;
    $config = $server->getConfig();
    expect($config)->toBeInstanceOf(ConfigurationRepositoryInterface::class);
});

test('it returns the logger instance', function () {
    $server = new Server;
    $logger = $server->getLogger();
    expect($logger)->toBeInstanceOf(LoggerInterface::class);
});

test('it returns the cache instance', function () {
    $server = new Server;
    $cache = $server->getCache();
    expect($cache)->toBeInstanceOf(CacheInterface::class);
});

test('it returns the container instance', function () {
    $server = new Server;
    $container = $server->getContainer();
    expect($container)->toBeInstanceOf(ContainerInterface::class);
});
