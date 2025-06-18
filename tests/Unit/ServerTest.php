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
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Server;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Utils\Discoverer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

beforeEach(function () {
    /** @var MockInterface&LoggerInterface $logger */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    /** @var MockInterface&LoopInterface $loop */
    $this->loop = Mockery::mock(LoopInterface::class)->shouldIgnoreMissing();
    /** @var MockInterface&CacheInterface $cache */
    $this->cache = Mockery::mock(CacheInterface::class);
    /** @var MockInterface&ContainerInterface $container */
    $this->container = Mockery::mock(ContainerInterface::class);

    $this->configuration = new Configuration(
        serverInfo: Implementation::make('TestServerInstance', '1.0'),
        capabilities: ServerCapabilities::make(),
        logger: $this->logger,
        loop: $this->loop,
        cache: $this->cache,
        container: $this->container
    );

    /** @var MockInterface&Registry $registry */
    $this->registry = Mockery::mock(Registry::class);
    /** @var MockInterface&Protocol $protocol */
    $this->protocol = Mockery::mock(Protocol::class);
    /** @var MockInterface&Discoverer $discoverer */
    $this->discoverer = Mockery::mock(Discoverer::class);

    $this->sessionManager = new SessionManager(new ArraySessionHandler(), $this->logger, $this->loop);

    $this->server = new Server(
        $this->configuration,
        $this->registry,
        $this->protocol,
        $this->sessionManager
    );

    $this->registry->allows('hasElements')->withNoArgs()->andReturn(false)->byDefault();
    $this->registry->allows('clear')->withAnyArgs()->byDefault();
    $this->registry->allows('save')->withAnyArgs()->andReturn(true)->byDefault();
});

afterEach(function () {
    $this->sessionManager->stopGcTimer();
});

it('provides getters for core components', function () {
    expect($this->server->getConfiguration())->toBe($this->configuration);
    expect($this->server->getRegistry())->toBe($this->registry);
    expect($this->server->getProtocol())->toBe($this->protocol);
    expect($this->server->getSessionManager())->toBe($this->sessionManager);
});

it('provides a static make method returning ServerBuilder', function () {
    expect(Server::make())->toBeInstanceOf(\PhpMcp\Server\ServerBuilder::class);
});

it('skips discovery if already run and not forced', function () {
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    $prop->setValue($this->server, true);

    $this->registry->shouldNotReceive('clear');
    $this->discoverer->shouldNotReceive('discover');
    $this->registry->shouldNotReceive('save');

    $this->server->discover(sys_get_temp_dir(), discoverer: $this->discoverer);
    $this->logger->shouldHaveReceived('debug')->with('Discovery skipped: Already run or loaded from cache.');
});

it('forces discovery even if already run, calling injected discoverer', function () {
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    $prop->setValue($this->server, true);

    $basePath = realpath(sys_get_temp_dir());
    $scanDirs = ['.', 'src'];


    $this->registry->shouldReceive('clear')->once();
    $this->discoverer->shouldReceive('discover')
        ->with($basePath, $scanDirs, Mockery::type('array'))
        ->once();
    $this->registry->shouldReceive('save')->once()->andReturn(true);

    $this->server->discover($basePath, $scanDirs, [], force: true, discoverer: $this->discoverer);

    expect($prop->getValue($this->server))->toBeTrue();
});

it('calls registry clear and discoverer, then saves to cache by default', function () {
    $basePath = realpath(sys_get_temp_dir());
    $scanDirs = ['app', 'lib'];
    $userExcludeDirs = ['specific_exclude'];
    $finalExcludeDirs = array_unique(array_merge(
        ['vendor', 'tests', 'test', 'storage', 'cache', 'samples', 'docs', 'node_modules', '.git', '.svn'],
        $userExcludeDirs
    ));


    $this->registry->shouldReceive('clear')->once();
    $this->discoverer->shouldReceive('discover')
        ->with($basePath, $scanDirs, Mockery::on(function ($arg) use ($finalExcludeDirs) {
            expect($arg)->toBeArray();
            expect($arg)->toEqualCanonicalizing($finalExcludeDirs);
            return true;
        }))
        ->once();
    $this->registry->shouldReceive('save')->once()->andReturn(true);

    $this->server->discover($basePath, $scanDirs, $userExcludeDirs, discoverer: $this->discoverer);

    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    expect($prop->getValue($this->server))->toBeTrue();
});

it('does not save to cache if saveToCache is false', function () {
    $basePath = realpath(sys_get_temp_dir());

    $this->registry->shouldReceive('clear')->once();
    $this->discoverer->shouldReceive('discover')->once();
    $this->registry->shouldNotReceive('save');

    $this->server->discover($basePath, saveToCache: false, discoverer: $this->discoverer);
});

it('throws InvalidArgumentException for bad base path in discover', function () {
    $this->discoverer->shouldNotReceive('discover');
    $this->server->discover('/non/existent/path/for/sure/I/hope', discoverer: $this->discoverer);
})->throws(\InvalidArgumentException::class, 'Invalid discovery base path');

it('throws DiscoveryException if Discoverer fails during discovery', function () {
    $basePath = realpath(sys_get_temp_dir());

    $this->registry->shouldReceive('clear')->once();
    $this->discoverer->shouldReceive('discover')->once()->andThrow(new \RuntimeException('Filesystem error'));
    $this->registry->shouldNotReceive('save');

    $this->server->discover($basePath, discoverer: $this->discoverer);
})->throws(DiscoveryException::class, 'Element discovery failed: Filesystem error');

it('resets discoveryRan flag on Discoverer failure', function () {
    $basePath = realpath(sys_get_temp_dir());
    $this->registry->shouldReceive('clear')->once();
    $this->discoverer->shouldReceive('discover')->once()->andThrow(new \RuntimeException('Failure'));

    try {
        $this->server->discover($basePath, discoverer: $this->discoverer);
    } catch (DiscoveryException $e) {
        // Expected
    }

    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('discoveryRan');
    $prop->setAccessible(true);
    expect($prop->getValue($this->server))->toBeFalse();
});


// --- Listening Tests ---
it('throws LogicException if listen is called when already listening', function () {
    $transport = Mockery::mock(ServerTransportInterface::class)->shouldIgnoreMissing();
    $this->protocol->shouldReceive('bindTransport')->with($transport)->once();

    $this->server->listen($transport, false);
    $this->server->listen($transport, false);
})->throws(LogicException::class, 'Server is already listening');

it('warns if no elements and discovery not run when listen is called', function () {
    $transport = Mockery::mock(ServerTransportInterface::class)->shouldIgnoreMissing();
    $this->protocol->shouldReceive('bindTransport')->with($transport)->once();

    $this->registry->shouldReceive('hasElements')->andReturn(false);

    $this->logger->shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/Starting listener, but no MCP elements are registered and discovery has not been run/'));

    $this->server->listen($transport, false);
});

it('injects logger and loop into aware transports during listen', function () {
    $transport = Mockery::mock(ServerTransportInterface::class, LoggerAwareInterface::class, LoopAwareInterface::class);
    $transport->shouldReceive('setLogger')->with($this->logger)->once();
    $transport->shouldReceive('setLoop')->with($this->loop)->once();
    $transport->shouldReceive('on', 'once', 'listen', 'emit', 'close', 'removeAllListeners')->withAnyArgs();
    $this->protocol->shouldReceive('bindTransport', 'unbindTransport')->withAnyArgs();

    $this->server->listen($transport);
});

it('binds protocol, starts transport listener, and runs loop by default', function () {
    $transport = Mockery::mock(ServerTransportInterface::class)->shouldIgnoreMissing();
    $transport->shouldReceive('listen')->once();
    $this->protocol->shouldReceive('bindTransport')->with($transport)->once();
    $this->loop->shouldReceive('run')->once();
    $this->protocol->shouldReceive('unbindTransport')->once();

    $this->server->listen($transport);
    expect(getPrivateProperty($this->server, 'isListening'))->toBeFalse();
});

it('does not run loop if runLoop is false in listen', function () {
    $transport = Mockery::mock(ServerTransportInterface::class)->shouldIgnoreMissing();
    $this->protocol->shouldReceive('bindTransport')->with($transport)->once();

    $this->loop->shouldNotReceive('run');

    $this->server->listen($transport, runLoop: false);
    expect(getPrivateProperty($this->server, 'isListening'))->toBeTrue();

    $this->protocol->shouldReceive('unbindTransport');
    $transport->shouldReceive('removeAllListeners');
    $transport->shouldReceive('close');
    $this->server->endListen($transport);
});

it('calls endListen if transport listen throws immediately', function () {
    $transport = Mockery::mock(ServerTransportInterface::class)->shouldIgnoreMissing();
    $transport->shouldReceive('listen')->once()->andThrow(new \RuntimeException("Port in use"));
    $this->protocol->shouldReceive('bindTransport')->once();
    $this->protocol->shouldReceive('unbindTransport')->once();

    $this->loop->shouldNotReceive('run');

    try {
        $this->server->listen($transport);
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe("Port in use");
    }
    expect(getPrivateProperty($this->server, 'isListening'))->toBeFalse();
});

it('endListen unbinds protocol and closes transport if listening', function () {
    $transport = Mockery::mock(ServerTransportInterface::class);
    $reflector = new \ReflectionClass($this->server);
    $prop = $reflector->getProperty('isListening');
    $prop->setAccessible(true);
    $prop->setValue($this->server, true);

    $this->protocol->shouldReceive('unbindTransport')->once();
    $transport->shouldReceive('removeAllListeners')->with('close')->once();
    $transport->shouldReceive('close')->once();

    $this->server->endListen($transport);
    expect($prop->getValue($this->server))->toBeFalse();
});
