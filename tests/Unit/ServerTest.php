<?php

namespace PhpMcp\Server\Tests\Unit;

use LogicException;
use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\DiscoveryException;
use PhpMcp\Server\Model\Capabilities;
use PhpMcp\Server\Processor;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Server;
use PhpMcp\Server\State\ClientStateManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

beforeEach(function () {
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->loop = Mockery::mock(LoopInterface::class);

    $cache = Mockery::mock(CacheInterface::class);
    $container = Mockery::mock(ContainerInterface::class);
    $capabilities = Capabilities::forServer();

    $this->configuration = new Configuration(
        'TestServerInstance',
        '1.0',
        $capabilities,
        $this->logger,
        $this->loop,
        $cache,
        $container
    );

    $this->registry = Mockery::mock(Registry::class);
    $this->processor = Mockery::mock(Processor::class);
    $this->clientStateManager = Mockery::mock(ClientStateManager::class);

    $this->server = new Server($this->configuration, $this->registry, $this->processor, $this->clientStateManager);

    $this->registry->allows('hasElements')->withNoArgs()->andReturn(false)->byDefault();
    $this->registry->allows('discoveryRanOrCached')->withNoArgs()->andReturn(false)->byDefault();
    $this->registry->allows('clearDiscoveredElements')->withAnyArgs()->andReturnNull()->byDefault();
    $this->registry->allows('saveDiscoveredElementsToCache')->withAnyArgs()->andReturn(true)->byDefault();
    $this->registry->allows('loadDiscoveredElementsFromCache')->withAnyArgs()->andReturnNull()->byDefault();
    $this->registry->allows('allTools->count')->withNoArgs()->andReturn(0)->byDefault();
    $this->registry->allows('allResources->count')->withNoArgs()->andReturn(0)->byDefault();
    $this->registry->allows('allResourceTemplates->count')->withNoArgs()->andReturn(0)->byDefault();
    $this->registry->allows('allPrompts->count')->withNoArgs()->andReturn(0)->byDefault();
});

it('provides getters for core components', function () {
    expect($this->server->getConfiguration())->toBe($this->configuration);
    expect($this->server->getRegistry())->toBe($this->registry);
    expect($this->server->getProcessor())->toBe($this->processor);
    expect($this->server->getClientStateManager())->toBe($this->clientStateManager);
});

it('gets protocol handler lazily', function () {
    $handler1 = $this->server->getProtocol();
    $handler2 = $this->server->getProtocol();
    expect($handler1)->toBeInstanceOf(Protocol::class);
    expect($handler2)->toBe($handler1);
});

it('skips discovery if already run and not forced', function () {
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    $prop->setValue($this->server, true);

    $this->registry->shouldNotReceive('clearDiscoveredElements');
    $this->registry->shouldNotReceive('saveDiscoveredElementsToCache');

    $this->server->discover(sys_get_temp_dir());

    $this->logger->shouldHaveReceived('debug')->with('Discovery skipped: Already run or loaded from cache.');
});

it('clears discovered elements before scanning', function () {
    $basePath = sys_get_temp_dir();

    $this->registry->shouldReceive('clearDiscoveredElements')->once()->with(true);
    $this->registry->shouldReceive('saveDiscoveredElementsToCache')->once()->andReturn(true);

    $this->server->discover($basePath);

    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    expect($prop->getValue($this->server))->toBeTrue();
});

it('saves to cache after discovery when requested', function () {
    // Arrange
    $basePath = sys_get_temp_dir();

    $this->registry->shouldReceive('clearDiscoveredElements')->once()->with(true);
    $this->registry->shouldReceive('saveDiscoveredElementsToCache')->once()->andReturn(true);

    // Act
    $this->server->discover($basePath, saveToCache: true);
});

it('does NOT save to cache after discovery when requested', function () {
    // Arrange
    $basePath = sys_get_temp_dir();

    $this->registry->shouldReceive('clearDiscoveredElements')->once()->with(false); // saveToCache=false -> deleteCacheFile=false
    $this->registry->shouldNotReceive('saveDiscoveredElementsToCache'); // Expect NOT to save

    // Act
    $this->server->discover($basePath, saveToCache: false);
});

it('throws InvalidArgumentException for bad base path', function () {
    $this->server->discover('/non/existent/path/for/sure');
})->throws(\InvalidArgumentException::class);

it('throws DiscoveryException if discoverer fails', function () {
    $basePath = sys_get_temp_dir();
    $exception = new \RuntimeException('Filesystem error');

    $this->registry->shouldReceive('clearDiscoveredElements')->once();
    $this->registry->shouldReceive('saveDiscoveredElementsToCache')->once()->andThrow($exception);

    $this->server->discover($basePath);

})->throws(DiscoveryException::class, 'Element discovery failed: Filesystem error');

it('resets discoveryRan flag on failure', function () {
    $basePath = sys_get_temp_dir();
    $exception = new \RuntimeException('Filesystem error');

    $this->registry->shouldReceive('clearDiscoveredElements')->once();
    $this->registry->shouldReceive('saveDiscoveredElementsToCache')->once()->andThrow($exception);

    try {
        $this->server->discover($basePath);
    } catch (DiscoveryException $e) {
        // Expected
    }

    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    expect($prop->getValue($this->server))->toBeFalse();
});

it('throws exception if already listening', function () {
    $transport = Mockery::mock(ServerTransportInterface::class);

    // Simulate the first listen call succeeding and setting the flag
    $transport->shouldReceive('setLogger', 'setLoop', 'on', 'once', 'removeListener', 'close')->withAnyArgs()->byDefault();
    $transport->shouldReceive('listen')->once(); // Expect listen on first call
    $transport->shouldReceive('emit')->withAnyArgs()->byDefault(); // Allow emit
    $this->loop->shouldReceive('run')->once()->andReturnUsing(fn () => $transport->emit('close')); // Simulate loop run for first call

    // Make the first call successful
    $this->server->listen($transport);

    // Reset mocks for the second call if necessary (though Mockery should handle it)
    // Now, mark as listening and try again
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('isListening');
    $prop->setAccessible(true);
    $prop->setValue($this->server, true); // Ensure flag is set

    // Act & Assert: Second call throws
    expect(fn () => $this->server->listen($transport))
        ->toThrow(LogicException::class, 'Server is already listening');
});

it('warns if no elements and discovery not run when trying to listen', function () {
    $transport = Mockery::mock(ServerTransportInterface::class);

    $this->registry->shouldReceive('hasElements')->andReturn(false);

    $this->logger->shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/Starting listener, but no MCP elements are registered and discovery has not been run/'));

    $transport->shouldReceive('setLogger', 'setLoop', 'on', 'once', 'removeListener', 'close')->withAnyArgs();
    $transport->shouldReceive('listen')->once();
    $transport->shouldReceive('emit')->withAnyArgs()->byDefault(); // Allow emit
    $this->loop->shouldReceive('run')->once()->andReturnUsing(fn () => $transport->emit('close'));

    $this->server->listen($transport);
});

it('warns if no elements found AFTER discovery when trying to listen', function () {
    $transport = Mockery::mock(ServerTransportInterface::class);

    // Setup: No elements, discoveryRan=true
    $this->registry->shouldReceive('hasElements')->andReturn(false);
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    $prop->setValue($this->server, true);

    $this->logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Starting listener, but no MCP elements were found after discovery/'));

    $transport->shouldReceive('setLogger', 'setLoop', 'on', 'once', 'removeListener', 'close')->withAnyArgs();
    $transport->shouldReceive('listen')->once();
    $transport->shouldReceive('emit')->withAnyArgs()->byDefault();
    $this->loop->shouldReceive('run')->once()->andReturnUsing(fn () => $transport->emit('close'));

    $this->server->listen($transport);
});

it('does not warn if elements are present when trying to listen', function () {
    $transport = Mockery::mock(ServerTransportInterface::class);

    // Setup: HAS elements
    $this->registry->shouldReceive('hasElements')->andReturn(true);

    $this->logger->shouldNotReceive('warning'); // Expect NO warning

    // --- FIX: Allow necessary mock calls ---
    $transport->shouldReceive('setLogger', 'setLoop', 'on', 'once', 'removeListener', 'close')->withAnyArgs();
    $transport->shouldReceive('listen')->once();
    $transport->shouldReceive('emit')->withAnyArgs()->byDefault();
    $this->loop->shouldReceive('run')->once()->andReturnUsing(fn () => $transport->emit('close'));

    $this->server->listen($transport);
});

it('injects logger and loop into aware transports when listening', function () {
    $transport = Mockery::mock(ServerTransportInterface::class, LoggerAwareInterface::class, LoopAwareInterface::class);
    $transport->shouldReceive('setLogger')->with($this->logger)->once();
    $transport->shouldReceive('setLoop')->with($this->loop)->once();
    $transport->shouldReceive('on', 'once', 'removeListener', 'close')->withAnyArgs();
    $transport->shouldReceive('listen')->once();
    $transport->shouldReceive('emit')->withAnyArgs()->byDefault(); // Allow emit
    $this->loop->shouldReceive('run')->once()->andReturnUsing(fn () => $transport->emit('close'));

    $this->server->listen($transport);
});

it('binds protocol handler and starts transport listen', function () {
    $transport = Mockery::mock(ServerTransportInterface::class);
    // Get the real handler instance but spy on it
    $protocolSpy = Mockery::spy($this->server->getProtocol());
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('protocol');
    $prop->setAccessible(true);
    $prop->setValue($this->server, $protocolSpy); // Inject spy

    // Expectations
    $protocolSpy->shouldReceive('bindTransport')->with($transport)->once();
    $transport->shouldReceive('listen')->once();
    $transport->shouldReceive('on', 'once', 'removeListener', 'close')->withAnyArgs(); // Allow listeners
    $transport->shouldReceive('emit')->withAnyArgs()->byDefault(); // Allow emit
    $this->loop->shouldReceive('run')->once()->andReturnUsing(fn () => $transport->emit('close'));
    $protocolSpy->shouldReceive('unbindTransport')->once(); // Expect unbind on close

    $this->server->listen($transport);
    // Mockery verifies expectations
});
