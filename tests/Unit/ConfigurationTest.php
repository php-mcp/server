<?php

namespace PhpMcp\Server\Tests\Unit; // Ensure namespace matches if you moved Configuration

use Mockery;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Model\Capabilities; // Import the Capabilities model
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\LoopInterface;

beforeEach(function () {
    $this->name = 'TestServer';
    $this->version = '1.1.0';
    $this->logger = Mockery::mock(LoggerInterface::class);
    $this->loop = Mockery::mock(LoopInterface::class);
    $this->cache = Mockery::mock(CacheInterface::class);
    $this->container = Mockery::mock(ContainerInterface::class);
    // Create a default Capabilities object for testing
    $this->capabilities = Capabilities::forServer();
});

afterEach(function () {
    Mockery::close();
});

it('constructs configuration object with all properties', function () {
    $ttl = 1800;
    // Pass the capabilities object to the constructor
    $config = new Configuration(
        serverName: $this->name,
        serverVersion: $this->version,
        capabilities: $this->capabilities, // Pass capabilities
        logger: $this->logger,
        loop: $this->loop,
        cache: $this->cache,
        container: $this->container,
        definitionCacheTtl: $ttl
    );

    expect($config->serverName)->toBe($this->name);
    expect($config->serverVersion)->toBe($this->version);
    expect($config->capabilities)->toBe($this->capabilities); // Assert capabilities
    expect($config->logger)->toBe($this->logger);
    expect($config->loop)->toBe($this->loop);
    expect($config->cache)->toBe($this->cache);
    expect($config->container)->toBe($this->container);
    expect($config->definitionCacheTtl)->toBe($ttl);
});

it('constructs configuration object with default TTL', function () {
    // Pass capabilities object
    $config = new Configuration(
        serverName: $this->name,
        serverVersion: $this->version,
        capabilities: $this->capabilities, // Pass capabilities
        logger: $this->logger,
        loop: $this->loop,
        cache: $this->cache,
        container: $this->container
        // No TTL provided
    );

    expect($config->definitionCacheTtl)->toBe(3600); // Default value
});

it('constructs configuration object with null cache', function () {
    // Pass capabilities object
    $config = new Configuration(
        serverName: $this->name,
        serverVersion: $this->version,
        capabilities: $this->capabilities, // Pass capabilities
        logger: $this->logger,
        loop: $this->loop,
        cache: null, // Explicitly null cache
        container: $this->container
    );

    expect($config->cache)->toBeNull();
});

it('constructs configuration object with specific capabilities', function () {
    // Create specific capabilities
    $customCaps = Capabilities::forServer(
        resourcesSubscribe: true,
        loggingEnabled: true,
        instructions: 'Use wisely.'
    );

    $config = new Configuration(
        serverName: $this->name,
        serverVersion: $this->version,
        capabilities: $customCaps, // Pass custom capabilities
        logger: $this->logger,
        loop: $this->loop,
        cache: null,
        container: $this->container
    );

    expect($config->capabilities)->toBe($customCaps);
    expect($config->capabilities->resourcesSubscribe)->toBeTrue();
    expect($config->capabilities->loggingEnabled)->toBeTrue();
    expect($config->capabilities->instructions)->toBe('Use wisely.');
});
