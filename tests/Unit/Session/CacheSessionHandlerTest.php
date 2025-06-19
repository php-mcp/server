<?php

namespace PhpMcp\Server\Tests\Unit\Session;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Session\CacheSessionHandler;
use PhpMcp\Server\Contracts\SessionHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use PhpMcp\Server\Tests\Mocks\Clock\FixedClock;

const SESSION_ID_CACHE_1 = 'cache-session-id-1';
const SESSION_ID_CACHE_2 = 'cache-session-id-2';
const SESSION_ID_CACHE_3 = 'cache-session-id-3';
const SESSION_DATA_CACHE_1 = '{"id":"cs1","data":{"a":1,"b":"foo"}}';
const SESSION_DATA_CACHE_2 = '{"id":"cs2","data":{"x":true,"y":null}}';
const SESSION_DATA_CACHE_3 = '{"id":"cs3","data":"simple string data"}';
const DEFAULT_TTL_CACHE = 3600;
const SESSION_INDEX_KEY_CACHE = 'mcp_session_index';

beforeEach(function () {
    $this->fixedClock = new FixedClock();
    /** @var MockInterface&CacheInterface $cache */
    $this->cache = Mockery::mock(CacheInterface::class);

    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([])->byDefault();
    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, Mockery::any())->andReturn(true)->byDefault();

    $this->handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);
});

it('implements SessionHandlerInterface', function () {
    expect($this->handler)->toBeInstanceOf(SessionHandlerInterface::class);
});

it('constructs with default TTL and SystemClock if no clock provided', function () {
    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheMock->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([])->byDefault();
    $handler = new CacheSessionHandler($cacheMock);

    expect($handler->ttl)->toBe(DEFAULT_TTL_CACHE);
    $reflection = new \ReflectionClass($handler);
    $clockProp = $reflection->getProperty('clock');
    $clockProp->setAccessible(true);
    expect($clockProp->getValue($handler))->toBeInstanceOf(\PhpMcp\Server\Defaults\SystemClock::class);
});

it('constructs with a custom TTL and injected clock', function () {
    $customTtl = 7200;
    $clock = new FixedClock();
    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheMock->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([])->byDefault();
    $handler = new CacheSessionHandler($cacheMock, $customTtl, $clock);
    expect($handler->ttl)->toBe($customTtl);

    $reflection = new \ReflectionClass($handler);
    $clockProp = $reflection->getProperty('clock');
    $clockProp->setAccessible(true);
    expect($clockProp->getValue($handler))->toBe($clock);
});

it('loads session index from cache on construction', function () {
    $initialTimestamp = $this->fixedClock->now()->modify('-100 seconds')->getTimestamp();
    $initialIndex = [SESSION_ID_CACHE_1 => $initialTimestamp];

    $cacheMock = Mockery::mock(CacheInterface::class);
    $cacheMock->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->once()->andReturn($initialIndex);

    new CacheSessionHandler($cacheMock, DEFAULT_TTL_CACHE, $this->fixedClock);
});

it('reads session data from cache', function () {
    $sessionIndex = [SESSION_ID_CACHE_1 => $this->fixedClock->now()->modify('-100 seconds')->getTimestamp()];
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->once()->andReturn($sessionIndex);
    $this->cache->shouldReceive('get')->with(SESSION_ID_CACHE_1, false)->once()->andReturn(SESSION_DATA_CACHE_1);

    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);
    $readData = $handler->read(SESSION_ID_CACHE_1);
    expect($readData)->toBe(SESSION_DATA_CACHE_1);
});

it('returns false when reading non-existent session (cache get returns default)', function () {
    $this->cache->shouldReceive('get')->with('non-existent-id', false)->once()->andReturn(false);
    $readData = $this->handler->read('non-existent-id');
    expect($readData)->toBeFalse();
});

it('writes session data to cache with correct key and TTL, and updates session index', function () {
    $expectedTimestamp = $this->fixedClock->now()->getTimestamp(); // 15:00:00

    $this->cache->shouldReceive('set')
        ->with(SESSION_INDEX_KEY_CACHE, [SESSION_ID_CACHE_1 => $expectedTimestamp])
        ->once()->andReturn(true);
    $this->cache->shouldReceive('set')
        ->with(SESSION_ID_CACHE_1, SESSION_DATA_CACHE_1)
        ->once()->andReturn(true);

    $writeResult = $this->handler->write(SESSION_ID_CACHE_1, SESSION_DATA_CACHE_1);
    expect($writeResult)->toBeTrue();
});

it('updates timestamp in session index for existing session on write', function () {
    $initialWriteTime = $this->fixedClock->now()->modify('-60 seconds')->getTimestamp(); // 14:59:00
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([SESSION_ID_CACHE_1 => $initialWriteTime]);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);

    $this->fixedClock->addSeconds(90);
    $expectedNewTimestamp = $this->fixedClock->now()->getTimestamp();

    $this->cache->shouldReceive('set')
        ->with(SESSION_INDEX_KEY_CACHE, [SESSION_ID_CACHE_1 => $expectedNewTimestamp])
        ->once()->andReturn(true);
    $this->cache->shouldReceive('set')
        ->with(SESSION_ID_CACHE_1, SESSION_DATA_CACHE_1)
        ->once()->andReturn(true);

    $handler->write(SESSION_ID_CACHE_1, SESSION_DATA_CACHE_1);
});

it('returns false if cache set for session data fails', function () {
    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, Mockery::any())->andReturn(true);
    $this->cache->shouldReceive('set')->with(SESSION_ID_CACHE_1, SESSION_DATA_CACHE_1)
        ->once()->andReturn(false);

    $writeResult = $this->handler->write(SESSION_ID_CACHE_1, SESSION_DATA_CACHE_1);
    expect($writeResult)->toBeFalse();
});

it('destroys session by removing from cache and updating index', function () {
    $initialTimestamp = $this->fixedClock->now()->getTimestamp();
    $initialIndex = [SESSION_ID_CACHE_1 => $initialTimestamp, SESSION_ID_CACHE_2 => $initialTimestamp];
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn($initialIndex);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);

    $this->cache->shouldReceive('set')
        ->with(SESSION_INDEX_KEY_CACHE, [SESSION_ID_CACHE_2 => $initialTimestamp])
        ->once()->andReturn(true);
    $this->cache->shouldReceive('delete')->with(SESSION_ID_CACHE_1)->once()->andReturn(true);

    $handler->destroy(SESSION_ID_CACHE_1);
});

it('destroy returns true if session ID not in index (cache delete still called)', function () {
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([]); // Empty index
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE);

    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, [])->once()->andReturn(true); // Index remains empty
    $this->cache->shouldReceive('delete')->with('non-existent-id')->once()->andReturn(true); // Cache delete for data

    $destroyResult = $handler->destroy('non-existent-id');
    expect($destroyResult)->toBeTrue();
});

it('destroy returns false if cache delete for session data fails', function () {
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([SESSION_ID_CACHE_1 => time()]);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE);

    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, Mockery::any())->andReturn(true); // Index update
    $this->cache->shouldReceive('delete')->with(SESSION_ID_CACHE_1)->once()->andReturn(false); // Data delete fails

    $destroyResult = $handler->destroy(SESSION_ID_CACHE_1);
    expect($destroyResult)->toBeFalse();
});

it('garbage collects only sessions older than maxLifetime from cache and index', function () {
    $maxLifetime = 120;

    $initialIndex = [
        SESSION_ID_CACHE_1 => $this->fixedClock->now()->modify('-60 seconds')->getTimestamp(),
        SESSION_ID_CACHE_2 => $this->fixedClock->now()->modify("-{$maxLifetime} seconds -10 seconds")->getTimestamp(),
        SESSION_ID_CACHE_3 => $this->fixedClock->now()->modify('-1000 seconds')->getTimestamp(),
    ];
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn($initialIndex);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);

    $this->cache->shouldReceive('delete')->with(SESSION_ID_CACHE_2)->once()->andReturn(true);
    $this->cache->shouldReceive('delete')->with(SESSION_ID_CACHE_3)->once()->andReturn(true);
    $this->cache->shouldNotReceive('delete')->with(SESSION_ID_CACHE_1);

    $expectedFinalIndex = [SESSION_ID_CACHE_1 => $initialIndex[SESSION_ID_CACHE_1]];
    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, $expectedFinalIndex)->once()->andReturn(true);

    $deletedSessionIds = $handler->gc($maxLifetime);

    expect($deletedSessionIds)->toBeArray()->toHaveCount(2)
        ->and($deletedSessionIds)->toContain(SESSION_ID_CACHE_2)
        ->and($deletedSessionIds)->toContain(SESSION_ID_CACHE_3);
});

it('garbage collection respects maxLifetime precisely for cache handler', function () {
    $maxLifetime = 60;

    $sessionTimestamp = $this->fixedClock->now()->modify("-{$maxLifetime} seconds")->getTimestamp();
    $initialIndex = [SESSION_ID_CACHE_1 => $sessionTimestamp];
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn($initialIndex);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);

    $this->cache->shouldNotReceive('delete');
    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, $initialIndex)->once()->andReturn(true);
    $deleted = $handler->gc($maxLifetime);
    expect($deleted)->toBeEmpty();

    $this->fixedClock->addSeconds(1);
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn($initialIndex);
    $handlerAfterTimeAdvance = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);

    $this->cache->shouldReceive('delete')->with(SESSION_ID_CACHE_1)->once()->andReturn(true);
    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, [])->once()->andReturn(true);
    $deleted2 = $handlerAfterTimeAdvance->gc($maxLifetime);
    expect($deleted2)->toEqual([SESSION_ID_CACHE_1]);
});


it('garbage collection handles an empty session index', function () {
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn([]);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE);

    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, [])->once()->andReturn(true);
    $this->cache->shouldNotReceive('delete');

    $deletedSessions = $handler->gc(DEFAULT_TTL_CACHE);
    expect($deletedSessions)->toBeArray()->toBeEmpty();
});

it('garbage collection continues updating index even if a cache delete fails', function () {
    $maxLifetime = 60;

    $initialIndex = [
        'expired_deleted_ok' => $this->fixedClock->now()->modify("-70 seconds")->getTimestamp(),
        'expired_delete_fails' => $this->fixedClock->now()->modify("-80 seconds")->getTimestamp(),
        'survivor' => $this->fixedClock->now()->modify('-30 seconds')->getTimestamp(),
    ];
    $this->cache->shouldReceive('get')->with(SESSION_INDEX_KEY_CACHE, [])->andReturn($initialIndex);
    $handler = new CacheSessionHandler($this->cache, DEFAULT_TTL_CACHE, $this->fixedClock);

    $this->cache->shouldReceive('delete')->with('expired_deleted_ok')->once()->andReturn(true);
    $this->cache->shouldReceive('delete')->with('expired_delete_fails')->once()->andReturn(false);

    $expectedFinalIndex = ['survivor' => $initialIndex['survivor']];
    $this->cache->shouldReceive('set')->with(SESSION_INDEX_KEY_CACHE, $expectedFinalIndex)->once()->andReturn(true);

    $deletedSessionIds = $handler->gc($maxLifetime);
    expect($deletedSessionIds)->toHaveCount(2)->toContain('expired_deleted_ok')->toContain('expired_delete_fails');
});
