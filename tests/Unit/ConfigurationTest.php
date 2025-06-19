<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Configuration;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\LoopInterface;

beforeEach(function () {
    $this->serverInfo = Implementation::make('TestServer', '1.1.0');
    $this->logger = Mockery::mock(LoggerInterface::class);
    $this->loop = Mockery::mock(LoopInterface::class);
    $this->cache = Mockery::mock(CacheInterface::class);
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->capabilities = ServerCapabilities::make();
});

afterEach(function () {
    Mockery::close();
});

it('constructs configuration object with all properties', function () {
    $paginationLimit = 100;
    $config = new Configuration(
        serverInfo: $this->serverInfo,
        capabilities: $this->capabilities,
        logger: $this->logger,
        loop: $this->loop,
        cache: $this->cache,
        container: $this->container,
        paginationLimit: $paginationLimit
    );

    expect($config->serverInfo)->toBe($this->serverInfo);
    expect($config->capabilities)->toBe($this->capabilities);
    expect($config->logger)->toBe($this->logger);
    expect($config->loop)->toBe($this->loop);
    expect($config->cache)->toBe($this->cache);
    expect($config->container)->toBe($this->container);
    expect($config->paginationLimit)->toBe($paginationLimit);
});

it('constructs configuration object with default pagination limit', function () {
    $config = new Configuration(
        serverInfo: $this->serverInfo,
        capabilities: $this->capabilities,
        logger: $this->logger,
        loop: $this->loop,
        cache: $this->cache,
        container: $this->container
    );

    expect($config->paginationLimit)->toBe(50); // Default value
});

it('constructs configuration object with null cache', function () {
    $config = new Configuration(
        serverInfo: $this->serverInfo,
        capabilities: $this->capabilities,
        logger: $this->logger,
        loop: $this->loop,
        cache: null,
        container: $this->container
    );

    expect($config->cache)->toBeNull();
});

it('constructs configuration object with specific capabilities', function () {
    $customCaps = ServerCapabilities::make(
        resourcesSubscribe: true,
        logging: true,
    );

    $config = new Configuration(
        serverInfo: $this->serverInfo,
        capabilities: $customCaps,
        logger: $this->logger,
        loop: $this->loop,
        cache: null,
        container: $this->container
    );

    expect($config->capabilities)->toBe($customCaps);
    expect($config->capabilities->resourcesSubscribe)->toBeTrue();
    expect($config->capabilities->logging)->toBeTrue();
});
