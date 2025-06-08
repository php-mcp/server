<?php

namespace PhpMcp\Server\Tests\Unit\State;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PhpMcp\Server\Defaults\ArrayCache;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\State\ClientState;
use PhpMcp\Server\State\ClientStateManager;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;

uses(MockeryPHPUnitIntegration::class);

const TEST_CLIENT_ID_CSM = 'test-csm-client-001';
const TEST_CLIENT_ID_CSM_2 = 'test-csm-client-002';
const TEST_URI_CSM_1 = 'file:///test-csm1.txt';
const TEST_URI_CSM_2 = 'config://app-csm';
const CLIENT_DATA_PREFIX_CSM = 'mcp_client_obj_';
const GLOBAL_RES_SUBS_PREFIX_CSM = 'mcp_res_subs_';
const GLOBAL_ACTIVE_KEY_CSM = 'mcp_active_clients';
const CACHE_TTL_CSM = 7200;

beforeEach(function () {
    $this->cache = Mockery::mock(CacheInterface::class);
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    // Instance WITH mocked cache for most tests
    $this->stateManagerWithCache = new ClientStateManager(
        $this->logger,
        $this->cache,
        CLIENT_DATA_PREFIX_CSM,
        CACHE_TTL_CSM
    );

    // Instance that will use its internal default ArrayCache
    $this->stateManagerWithDefaultCache = new ClientStateManager(
        $this->logger,
        null,
        CLIENT_DATA_PREFIX_CSM,
        CACHE_TTL_CSM
    );
});

afterEach(function () {
    Mockery::close();
});

function getClientStateKey(string $clientId): string
{
    return CLIENT_DATA_PREFIX_CSM . $clientId;
}
function getResourceSubscribersKey(string $uri): string
{
    return GLOBAL_RES_SUBS_PREFIX_CSM . sha1($uri);
}
function getActiveClientsKey(): string
{
    return CLIENT_DATA_PREFIX_CSM . ClientStateManager::GLOBAL_ACTIVE_CLIENTS_KEY;
}

it('uses provided cache or defaults to ArrayCache', function () {
    // Verify with provided cache
    $reflector = new \ReflectionClass($this->stateManagerWithCache);
    $cacheProp = $reflector->getProperty('cache');
    $cacheProp->setAccessible(true);
    expect($cacheProp->getValue($this->stateManagerWithCache))->toBe($this->cache);

    // Verify with default ArrayCache
    $reflectorNoCache = new \ReflectionClass($this->stateManagerWithDefaultCache);
    $cachePropNoCache = $reflectorNoCache->getProperty('cache');
    $cachePropNoCache->setAccessible(true);
    expect($cachePropNoCache->getValue($this->stateManagerWithDefaultCache))->toBeInstanceOf(ArrayCache::class);
});

it('returns existing state object from cache', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $mockedClientState = new ClientState(TEST_CLIENT_ID_CSM);
    $mockedClientState->isInitialized = true;

    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($mockedClientState);

    $reflector = new \ReflectionClass($this->stateManagerWithCache);
    $method = $reflector->getMethod('getClientState');
    $method->setAccessible(true);
    $state = $method->invoke($this->stateManagerWithCache, TEST_CLIENT_ID_CSM);

    expect($state)->toBe($mockedClientState);
});

it('creates new state if not found and createIfNotFound is true', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn(null); // Cache miss

    $reflector = new \ReflectionClass($this->stateManagerWithCache);
    $method = $reflector->getMethod('getClientState');
    $method->setAccessible(true);
    $state = $method->invoke($this->stateManagerWithCache, TEST_CLIENT_ID_CSM, true); // createIfNotFound = true

    expect($state)->toBeInstanceOf(ClientState::class);
    expect($state->isInitialized)->toBeFalse(); // New state default
});

it('returns null if not found and createIfNotFound is false', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn(null);

    $reflector = new \ReflectionClass($this->stateManagerWithCache);
    $method = $reflector->getMethod('getClientState');
    $method->setAccessible(true);
    $state = $method->invoke($this->stateManagerWithCache, TEST_CLIENT_ID_CSM, false); // createIfNotFound = false

    expect($state)->toBeNull();
});

it('deletes invalid data from cache', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn('not a ClientState object');
    $this->cache->shouldReceive('delete')->once()->with($clientStateKey)->andReturn(true);
    $this->logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Invalid data type found in cache for client state/'), Mockery::any());

    $reflector = new \ReflectionClass($this->stateManagerWithCache);
    $method = $reflector->getMethod('getClientState');
    $method->setAccessible(true);
    $state = $method->invoke($this->stateManagerWithCache, TEST_CLIENT_ID_CSM, true); // Try to create

    expect($state)->toBeInstanceOf(ClientState::class); // Should create a new one
});

it('saves state in cache and updates timestamp', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $clientState = new ClientState(TEST_CLIENT_ID_CSM);
    $initialTimestamp = $clientState->lastActivityTimestamp;

    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(function (ClientState $state) use ($initialTimestamp) {
            return $state->lastActivityTimestamp >= $initialTimestamp;
        }), CACHE_TTL_CSM)
        ->andReturn(true);

    $reflector = new \ReflectionClass($this->stateManagerWithCache);
    $method = $reflector->getMethod('saveClientState');
    $method->setAccessible(true);
    $success = $method->invoke($this->stateManagerWithCache, TEST_CLIENT_ID_CSM, $clientState);

    expect($success)->toBeTrue();
    expect($clientState->lastActivityTimestamp)->toBeGreaterThanOrEqual($initialTimestamp); // Timestamp updated
});

// --- Initialization ---
test('gets client state and checks if initialized', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $state = new ClientState(TEST_CLIENT_ID_CSM);
    $state->isInitialized = true;
    $this->cache->shouldReceive('get')->with($clientStateKey)->andReturn($state);
    expect($this->stateManagerWithCache->isInitialized(TEST_CLIENT_ID_CSM))->toBeTrue();

    $stateNotInit = new ClientState(TEST_CLIENT_ID_CSM);
    $this->cache->shouldReceive('get')->with(getClientStateKey('client2'))->andReturn($stateNotInit);
    expect($this->stateManagerWithCache->isInitialized('client2'))->toBeFalse();
});

it('updates client state and global active list when client is initialized', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $activeClientsKey = getActiveClientsKey();

    // getClientState (createIfNotFound=true)
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn(null); // Simulate not found
    // saveClientState
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => $s->isInitialized === true), CACHE_TTL_CSM)
        ->andReturn(true);
    // updateGlobalActiveClientTimestamp
    $this->cache->shouldReceive('get')->once()->with($activeClientsKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->with($activeClientsKey, Mockery::hasKey(TEST_CLIENT_ID_CSM), CACHE_TTL_CSM)->andReturn(true);
    $this->logger->shouldReceive('info')->with('ClientStateManager: Client marked initialized.', Mockery::any());

    $this->stateManagerWithCache->markInitialized(TEST_CLIENT_ID_CSM);
});

// --- Client Info ---
it('updates client state when client info is stored', function () {
    $clientInfo = ['name' => 'X', 'v' => '2'];
    $proto = 'P1';
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);

    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn(null); // Create new
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(function (ClientState $s) use ($clientInfo, $proto) {
            return $s->clientInfo === $clientInfo && $s->protocolVersion === $proto;
        }), CACHE_TTL_CSM)
        ->andReturn(true);

    $this->stateManagerWithCache->storeClientInfo($clientInfo, $proto, TEST_CLIENT_ID_CSM);
});

// getClientInfo and getProtocolVersion now use null-safe operator, tests simplify
it('retrieves client info from ClientState', function () {
    $clientInfo = ['name' => 'Y'];
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $state = new ClientState(TEST_CLIENT_ID_CSM);
    $state->clientInfo = $clientInfo;
    $this->cache->shouldReceive('get')->with($clientStateKey)->andReturn($state);
    expect($this->stateManagerWithCache->getClientInfo(TEST_CLIENT_ID_CSM))->toBe($clientInfo);

    $this->cache->shouldReceive('get')->with(getClientStateKey('none'))->andReturn(null);
    expect($this->stateManagerWithCache->getClientInfo('none'))->toBeNull();
});

// --- Subscriptions ---
it('updates client state and global resource list when a resource subscription is added', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $resSubKey = getResourceSubscribersKey(TEST_URI_CSM_1);

    // getClientState (create)
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn(null);
    // saveClientState
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => isset($s->subscriptions[TEST_URI_CSM_1])), CACHE_TTL_CSM)
        ->andReturn(true);
    // Global resource sub update
    $this->cache->shouldReceive('get')->once()->with($resSubKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->with($resSubKey, [TEST_CLIENT_ID_CSM => true], CACHE_TTL_CSM)->andReturn(true);

    $this->stateManagerWithCache->addResourceSubscription(TEST_CLIENT_ID_CSM, TEST_URI_CSM_1);
});

it('updates client state and global resource list when a resource subscription is removed', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $resSubKey = getResourceSubscribersKey(TEST_URI_CSM_1);

    $initialClientState = new ClientState(TEST_CLIENT_ID_CSM);
    $initialClientState->addSubscription(TEST_URI_CSM_1);
    $initialClientState->addSubscription(TEST_URI_CSM_2);

    // getClientState
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($initialClientState);
    // saveClientState (after removing TEST_URI_CSM_1 from client's list)
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => ! isset($s->subscriptions[TEST_URI_CSM_1]) && isset($s->subscriptions[TEST_URI_CSM_2])), CACHE_TTL_CSM)
        ->andReturn(true);
    // Global resource sub update
    $this->cache->shouldReceive('get')->once()->with($resSubKey, [])->andReturn([TEST_CLIENT_ID_CSM => true, 'other' => true]);
    $this->cache->shouldReceive('set')->once()->with($resSubKey, ['other' => true], CACHE_TTL_CSM)->andReturn(true);

    $this->stateManagerWithCache->removeResourceSubscription(TEST_CLIENT_ID_CSM, TEST_URI_CSM_1);
});

it('clears from ClientState and all global lists when all resource subscriptions are removed', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $resSubKey1 = getResourceSubscribersKey(TEST_URI_CSM_1);
    $resSubKey2 = getResourceSubscribersKey(TEST_URI_CSM_2);

    $initialClientState = new ClientState(TEST_CLIENT_ID_CSM);
    $initialClientState->addSubscription(TEST_URI_CSM_1);
    $initialClientState->addSubscription(TEST_URI_CSM_2);

    // Get client state
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($initialClientState);
    // Save client state with empty subscriptions
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => empty($s->subscriptions)), CACHE_TTL_CSM)
        ->andReturn(true);

    // Interaction with global resource sub list for URI 1
    $this->cache->shouldReceive('get')->once()->with($resSubKey1, [])->andReturn([TEST_CLIENT_ID_CSM => true, 'other' => true]);
    $this->cache->shouldReceive('set')->once()->with($resSubKey1, ['other' => true], CACHE_TTL_CSM)->andReturn(true);
    // Interaction with global resource sub list for URI 2
    $this->cache->shouldReceive('get')->once()->with($resSubKey2, [])->andReturn([TEST_CLIENT_ID_CSM => true]);
    $this->cache->shouldReceive('delete')->once()->with($resSubKey2)->andReturn(true); // Becomes empty

    $this->stateManagerWithCache->removeAllResourceSubscriptions(TEST_CLIENT_ID_CSM);
});

it('can retrieve global resource list', function () {
    $resSubKey = getResourceSubscribersKey(TEST_URI_CSM_1);
    $this->cache->shouldReceive('get')->once()->with($resSubKey, [])->andReturn([TEST_CLIENT_ID_CSM => true, 'c2' => true]);
    expect($this->stateManagerWithCache->getResourceSubscribers(TEST_URI_CSM_1))->toEqualCanonicalizing([TEST_CLIENT_ID_CSM, 'c2']);
});

it('can check if a client is subscribed to a resource', function () {
    $resSubKey = getResourceSubscribersKey(TEST_URI_CSM_1);
    $this->cache->shouldReceive('get')->with($resSubKey, [])->andReturn([TEST_CLIENT_ID_CSM => true]);

    expect($this->stateManagerWithCache->isSubscribedToResource(TEST_CLIENT_ID_CSM, TEST_URI_CSM_1))->toBeTrue();
    expect($this->stateManagerWithCache->isSubscribedToResource('other_client', TEST_URI_CSM_1))->toBeFalse();
});

// --- Message Queue ---
it('can add a message to the client state queue', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $notification = json_encode((new Notification('2.0', 'event'))->toArray());
    $initialState = new ClientState(TEST_CLIENT_ID_CSM);

    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($initialState);
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(function (ClientState $s) use ($notification) {
            return count($s->messageQueue) === 1 && $s->messageQueue[0] == $notification;
        }), CACHE_TTL_CSM)
        ->andReturn(true);

    $this->stateManagerWithCache->queueMessage(TEST_CLIENT_ID_CSM, $notification);
});

it('consumes from ClientState queue and saves', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $messagesData = [json_encode(['method' => 'm1']), json_encode(['method' => 'm2'])];
    $initialState = new ClientState(TEST_CLIENT_ID_CSM);
    $initialState->messageQueue = $messagesData;

    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($initialState);
    $this->cache->shouldReceive('set')->once() // Expect save after consuming
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => empty($s->messageQueue)), CACHE_TTL_CSM)
        ->andReturn(true);

    $retrieved = $this->stateManagerWithCache->getQueuedMessages(TEST_CLIENT_ID_CSM);
    expect($retrieved)->toEqual($messagesData);
});

// --- Log Level Management ---
it('updates client state when log level is set', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $level = 'debug';

    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn(null); // Create new
    $this->cache->shouldReceive('set')->once()
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => $s->requestedLogLevel === $level), CACHE_TTL_CSM)
        ->andReturn(true);

    $this->stateManagerWithCache->setClientRequestedLogLevel(TEST_CLIENT_ID_CSM, $level);
});

it('can retrieve client requested log level', function () {
    $level = 'info';
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $state = new ClientState(TEST_CLIENT_ID_CSM);
    $state->requestedLogLevel = $level;
    $this->cache->shouldReceive('get')->with($clientStateKey)->andReturn($state);

    expect($this->stateManagerWithCache->getClientRequestedLogLevel(TEST_CLIENT_ID_CSM))->toBe($level);

    $this->cache->shouldReceive('get')->with(getClientStateKey('none_set'))->andReturn(new ClientState('none_set'));
    expect($this->stateManagerWithCache->getClientRequestedLogLevel('none_set'))->toBeNull();
});

// --- Client Management ---
it('performs all cleanup steps', function ($removeFromActive) {
    $clientId = 'client-mgr-cleanup';
    $clientStateKey = getClientStateKey($clientId);
    $activeClientsKey = getActiveClientsKey();

    $initialClientState = new ClientState($clientId);
    $initialClientState->addSubscription(TEST_URI_CSM_1);
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($initialClientState); // For removeAllResourceSubscriptions
    $this->cache->shouldReceive('set')->once()->with($clientStateKey, Mockery::on(fn (ClientState $s) => empty($s->subscriptions)), CACHE_TTL_CSM); // For removeAll...
    $resSubKey1 = getResourceSubscribersKey(TEST_URI_CSM_1);
    $this->cache->shouldReceive('get')->once()->with($resSubKey1, [])->andReturn([$clientId => true]);
    $this->cache->shouldReceive('delete')->once()->with($resSubKey1); // Becomes empty

    $this->cache->shouldReceive('delete')->once()->with($clientStateKey)->andReturn(true);

    if ($removeFromActive) {
        $this->cache->shouldReceive('get')->once()->with($activeClientsKey, [])->andReturn([$clientId => time(), 'other' => time()]);
        $this->cache->shouldReceive('set')->once()->with($activeClientsKey, Mockery::on(fn ($arr) => ! isset($arr[$clientId])), CACHE_TTL_CSM)->andReturn(true);
    } else {
        $this->cache->shouldNotReceive('get')->with($activeClientsKey, []); // Should not touch active list
    }

    $this->stateManagerWithCache->cleanupClient($clientId, $removeFromActive);
})->with([
    'Remove From Active List' => [true],
    'Keep In Active List (manual)' => [false],
]);

it('updates client state and global list when client activity is updated', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $activeClientsKey = getActiveClientsKey();
    $initialState = new ClientState(TEST_CLIENT_ID_CSM);
    $initialActivityTime = $initialState->lastActivityTimestamp;

    $this->cache->shouldReceive('get')->once()->with($clientStateKey)->andReturn($initialState);
    $this->cache->shouldReceive('set')->once() // Save ClientState
        ->with($clientStateKey, Mockery::on(fn (ClientState $s) => $s->lastActivityTimestamp >= $initialActivityTime), CACHE_TTL_CSM)
        ->andReturn(true);
    $this->cache->shouldReceive('get')->once()->with($activeClientsKey, [])->andReturn([]); // Update global
    $this->cache->shouldReceive('set')->once()->with($activeClientsKey, Mockery::on(fn ($arr) => $arr[TEST_CLIENT_ID_CSM] >= $initialActivityTime), CACHE_TTL_CSM)->andReturn(true);

    $this->stateManagerWithCache->updateClientActivity(TEST_CLIENT_ID_CSM);
});

it('filters and cleans up inactive clients when getting active clients', function () {
    $activeKey = getActiveClientsKey();
    $active1 = 'active1';
    $inactive1 = 'inactive1';
    $invalid1 = 'invalid_ts_client';
    $now = time();
    $activeData = [$active1 => $now - 10, $inactive1 => $now - 400, $invalid1 => 'not-a-timestamp'];
    $expectedFinalActiveInCache = [$active1 => $activeData[$active1]]; // Only active1 remains

    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn($activeData);
    $this->cache->shouldReceive('set')->once()->with($activeKey, $expectedFinalActiveInCache, CACHE_TTL_CSM)->andReturn(true);

    $inactiveClientState = new ClientState($inactive1);
    $this->cache->shouldReceive('get')->once()->with(getClientStateKey($inactive1))->andReturn($inactiveClientState);
    $this->cache->shouldReceive('delete')->once()->with(getClientStateKey($inactive1));

    $invalidClientState = new ClientState($invalid1);
    $this->cache->shouldReceive('get')->once()->with(getClientStateKey($invalid1))->andReturn($invalidClientState);
    $this->cache->shouldReceive('delete')->once()->with(getClientStateKey($invalid1));

    $result = $this->stateManagerWithCache->getActiveClients(300);
    expect($result)->toEqual([$active1]);
});

it('can get last activity time', function () {
    $activeKey = getActiveClientsKey();
    $now = time();
    $cacheData = [TEST_CLIENT_ID_CSM => $now - 50, 'other' => $now - 100];
    $this->cache->shouldReceive('get')->with($activeKey, [])->times(3)->andReturn($cacheData);

    expect($this->stateManagerWithCache->getLastActivityTime(TEST_CLIENT_ID_CSM))->toBe($now - 50);
    expect($this->stateManagerWithCache->getLastActivityTime('other'))->toBe($now - 100);
    expect($this->stateManagerWithCache->getLastActivityTime('nonexistent'))->toBeNull();
});

it('gracefully handles cache exception', function () {
    $clientStateKey = getClientStateKey(TEST_CLIENT_ID_CSM);
    $this->cache->shouldReceive('get')->once()->with($clientStateKey)
        ->andThrow(new class () extends \Exception implements CacheInvalidArgumentException {});
    $this->logger->shouldReceive('error')->once()->with(Mockery::pattern('/Error fetching client state from cache/'), Mockery::any());

    expect($this->stateManagerWithCache->getClientInfo(TEST_CLIENT_ID_CSM))->toBeNull();
});
