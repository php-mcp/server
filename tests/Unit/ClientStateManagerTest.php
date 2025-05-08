<?php

namespace PhpMcp\Server\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\ClientStateManager;
use PhpMcp\Server\JsonRpc\Notification;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;

// Define constants for the test file
const TEST_CLIENT_ID_MGR = 'test-mgr-client-789';
const TEST_URI_MGR_1 = 'file:///test-mgr.txt';
const TEST_URI_MGR_2 = 'config://app-mgr';
const CACHE_PREFIX_MGR = 'mcp_mgr_test_';
const CACHE_TTL_MGR = 600; // Example TTL

beforeEach(function () {
    $this->cache = Mockery::mock(CacheInterface::class);
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    // Instance WITH cache
    $this->stateManager = new ClientStateManager(
        $this->logger,
        $this->cache,
        CACHE_PREFIX_MGR,
        CACHE_TTL_MGR
    );

    // Instance WITHOUT cache
    $this->stateManagerNoCache = new ClientStateManager($this->logger, null);
});

afterEach(function () {
    Mockery::close();
});

// Helper to generate expected cache keys for this test file
function getMgrCacheKey(string $key, ?string $clientId = null): string
{
    return $clientId ? CACHE_PREFIX_MGR."{$key}_{$clientId}" : CACHE_PREFIX_MGR.$key;
}

// --- Tests ---

// --- Initialization ---
test('isInitialized returns false if cache unavailable', function () {
    expect($this->stateManagerNoCache->isInitialized(TEST_CLIENT_ID_MGR))->toBeFalse();
});

test('isInitialized checks cache correctly', function () {
    $initializedKey = getMgrCacheKey('initialized', TEST_CLIENT_ID_MGR);
    $this->cache->shouldReceive('get')->once()->with($initializedKey, false)->andReturn(false);
    $this->cache->shouldReceive('get')->once()->with($initializedKey, false)->andReturn(true);

    expect($this->stateManager->isInitialized(TEST_CLIENT_ID_MGR))->toBeFalse();
    expect($this->stateManager->isInitialized(TEST_CLIENT_ID_MGR))->toBeTrue();
});

test('markInitialized logs warning if cache unavailable', function () {
    $this->logger->shouldReceive('warning')->once()->with(Mockery::pattern('/cache not available/'), Mockery::any());
    $this->stateManagerNoCache->markInitialized(TEST_CLIENT_ID_MGR); // No exception thrown
});

test('markInitialized sets cache and updates activity', function () {
    $initializedKey = getMgrCacheKey('initialized', TEST_CLIENT_ID_MGR);
    $activityKey = getMgrCacheKey('active_clients');

    $this->cache->shouldReceive('set')->once()->with($initializedKey, true, CACHE_TTL_MGR)->andReturn(true);
    // Expect updateClientActivity call
    $this->cache->shouldReceive('get')->once()->with($activityKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->with($activityKey, Mockery::on(fn ($arg) => isset($arg[TEST_CLIENT_ID_MGR])), CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->markInitialized(TEST_CLIENT_ID_MGR); // No exception thrown
});

test('markInitialized handles cache exceptions', function () {
    $initializedKey = getMgrCacheKey('initialized', TEST_CLIENT_ID_MGR);
    $this->cache->shouldReceive('set')->once()->with($initializedKey, true, CACHE_TTL_MGR)->andThrow(new class () extends \Exception implements CacheInvalidArgumentException {});
    $this->logger->shouldReceive('error')->once()->with(Mockery::pattern('/Failed to mark client.*invalid key/'), Mockery::any());

    $this->stateManager->markInitialized(TEST_CLIENT_ID_MGR); // No exception thrown outwards
});

// --- Client Info ---
test('storeClientInfo does nothing if cache unavailable', function () {
    $this->cache->shouldNotReceive('set');
    $this->stateManagerNoCache->storeClientInfo([], 'v1', TEST_CLIENT_ID_MGR);
});

test('storeClientInfo sets cache keys', function () {
    $clientInfoKey = getMgrCacheKey('client_info', TEST_CLIENT_ID_MGR);
    $protocolKey = getMgrCacheKey('protocol_version', TEST_CLIENT_ID_MGR);
    $clientInfo = ['name' => 'TestClientState', 'version' => '1.1'];
    $protocolVersion = '2024-11-05';

    $this->cache->shouldReceive('set')->once()->with($clientInfoKey, $clientInfo, CACHE_TTL_MGR)->andReturn(true);
    $this->cache->shouldReceive('set')->once()->with($protocolKey, $protocolVersion, CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->storeClientInfo($clientInfo, $protocolVersion, TEST_CLIENT_ID_MGR);
});

test('getClientInfo returns null if cache unavailable', function () {
    expect($this->stateManagerNoCache->getClientInfo(TEST_CLIENT_ID_MGR))->toBeNull();
});

test('getClientInfo gets value from cache', function () {
    $clientInfoKey = getMgrCacheKey('client_info', TEST_CLIENT_ID_MGR);
    $clientInfo = ['name' => 'TestClientStateGet', 'version' => '1.2'];
    $this->cache->shouldReceive('get')->once()->with($clientInfoKey)->andReturn($clientInfo);

    expect($this->stateManager->getClientInfo(TEST_CLIENT_ID_MGR))->toBe($clientInfo);
});

test('getProtocolVersion returns null if cache unavailable', function () {
    expect($this->stateManagerNoCache->getProtocolVersion(TEST_CLIENT_ID_MGR))->toBeNull();
});

test('getProtocolVersion gets value from cache', function () {
    $protocolKey = getMgrCacheKey('protocol_version', TEST_CLIENT_ID_MGR);
    $protocolVersion = '2024-11-05-test';
    $this->cache->shouldReceive('get')->once()->with($protocolKey)->andReturn($protocolVersion);

    expect($this->stateManager->getProtocolVersion(TEST_CLIENT_ID_MGR))->toBe($protocolVersion);
});

// --- Subscriptions ---
test('addResourceSubscription logs warning if cache unavailable', function () {
    $this->logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Cannot add resource subscription.*cache not available/'), Mockery::any());
    $this->stateManagerNoCache->addResourceSubscription(TEST_CLIENT_ID_MGR, TEST_URI_MGR_1);
});

test('addResourceSubscription sets cache keys', function () {
    $clientSubKey = getMgrCacheKey('client_subscriptions', TEST_CLIENT_ID_MGR);
    $resourceSubKey = getMgrCacheKey('resource_subscriptions', TEST_URI_MGR_1);

    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn(['other/uri' => true]); // Existing data
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey, [])->andReturn(['other_client' => true]);
    $this->cache->shouldReceive('set')->once()->with($clientSubKey, ['other/uri' => true, TEST_URI_MGR_1 => true], CACHE_TTL_MGR)->andReturn(true);
    $this->cache->shouldReceive('set')->once()->with($resourceSubKey, ['other_client' => true, TEST_CLIENT_ID_MGR => true], CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->addResourceSubscription(TEST_CLIENT_ID_MGR, TEST_URI_MGR_1);
});

test('isSubscribedToResource returns false if cache unavailable', function () {
    expect($this->stateManagerNoCache->isSubscribedToResource(TEST_CLIENT_ID_MGR, TEST_URI_MGR_1))->toBeFalse();
});

test('isSubscribedToResource checks cache correctly', function () {
    $clientSubKey = getMgrCacheKey('client_subscriptions', TEST_CLIENT_ID_MGR);
    $this->cache->shouldReceive('get')->with($clientSubKey, [])->andReturn([TEST_URI_MGR_1 => true, 'another/one' => true]);

    expect($this->stateManager->isSubscribedToResource(TEST_CLIENT_ID_MGR, TEST_URI_MGR_1))->toBeTrue();
    expect($this->stateManager->isSubscribedToResource(TEST_CLIENT_ID_MGR, TEST_URI_MGR_2))->toBeFalse();
});

test('getResourceSubscribers returns empty array if cache unavailable', function () {
    expect($this->stateManagerNoCache->getResourceSubscribers(TEST_URI_MGR_1))->toBe([]);
});

test('getResourceSubscribers gets subscribers from cache', function () {
    $resourceSubKey = getMgrCacheKey('resource_subscriptions', TEST_URI_MGR_1);
    $this->cache->shouldReceive('get')->with($resourceSubKey, [])->andReturn([TEST_CLIENT_ID_MGR => true, 'client2' => true]);

    expect($this->stateManager->getResourceSubscribers(TEST_URI_MGR_1))->toEqualCanonicalizing([TEST_CLIENT_ID_MGR, 'client2']);
});

test('removeResourceSubscription does nothing if cache unavailable', function () {
    $this->cache->shouldNotReceive('get');
    $this->cache->shouldNotReceive('set');
    $this->cache->shouldNotReceive('delete');
    $this->stateManagerNoCache->removeResourceSubscription(TEST_CLIENT_ID_MGR, TEST_URI_MGR_1);
});

test('removeResourceSubscription removes keys and deletes if empty', function () {
    $clientSubKey = getMgrCacheKey('client_subscriptions', TEST_CLIENT_ID_MGR);
    $resourceSubKey1 = getMgrCacheKey('resource_subscriptions', TEST_URI_MGR_1);
    $resourceSubKey2 = getMgrCacheKey('resource_subscriptions', TEST_URI_MGR_2);

    // Initial state
    $clientSubs = [TEST_URI_MGR_1 => true, TEST_URI_MGR_2 => true];
    $res1Subs = [TEST_CLIENT_ID_MGR => true, 'other' => true];
    $res2Subs = [TEST_CLIENT_ID_MGR => true]; // Only this client

    // Mocks for removing sub for TEST_URI_MGR_1
    $this->cache->shouldReceive('get')->with($clientSubKey, [])->once()->andReturn($clientSubs);
    $this->cache->shouldReceive('get')->with($resourceSubKey1, [])->once()->andReturn($res1Subs);
    $this->cache->shouldReceive('set')->with($clientSubKey, [TEST_URI_MGR_2 => true], CACHE_TTL_MGR)->once()->andReturn(true); // URI 1 removed
    $this->cache->shouldReceive('set')->with($resourceSubKey1, ['other' => true], CACHE_TTL_MGR)->once()->andReturn(true); // Client removed

    $this->stateManager->removeResourceSubscription(TEST_CLIENT_ID_MGR, TEST_URI_MGR_1);

    // Mocks for removing sub for TEST_URI_MGR_2 (which will cause deletes)
    $this->cache->shouldReceive('get')->with($clientSubKey, [])->once()->andReturn([TEST_URI_MGR_2 => true]); // State after previous call
    $this->cache->shouldReceive('get')->with($resourceSubKey2, [])->once()->andReturn($res2Subs);
    $this->cache->shouldReceive('delete')->with($clientSubKey)->once()->andReturn(true); // Client list now empty
    $this->cache->shouldReceive('delete')->with($resourceSubKey2)->once()->andReturn(true); // Resource list now empty

    $this->stateManager->removeResourceSubscription(TEST_CLIENT_ID_MGR, TEST_URI_MGR_2);
});

test('removeAllResourceSubscriptions does nothing if cache unavailable', function () {
    $this->cache->shouldNotReceive('get');
    $this->cache->shouldNotReceive('delete');
    $this->stateManagerNoCache->removeAllResourceSubscriptions(TEST_CLIENT_ID_MGR);
});

test('removeAllResourceSubscriptions clears relevant cache entries', function () {
    $clientSubKey = getMgrCacheKey('client_subscriptions', TEST_CLIENT_ID_MGR);
    $resourceSubKey1 = getMgrCacheKey('resource_subscriptions', TEST_URI_MGR_1);
    $resourceSubKey2 = getMgrCacheKey('resource_subscriptions', TEST_URI_MGR_2);

    $initialClientSubs = [TEST_URI_MGR_1 => true, TEST_URI_MGR_2 => true];
    $initialResourceSubs1 = [TEST_CLIENT_ID_MGR => true, 'other' => true];
    $initialResourceSubs2 = [TEST_CLIENT_ID_MGR => true];

    // Get the client's subscription list
    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn($initialClientSubs);
    // Get the subscriber list for each resource the client was subscribed to
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey1, [])->andReturn($initialResourceSubs1);
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey2, [])->andReturn($initialResourceSubs2);
    // Update the first resource's list
    $this->cache->shouldReceive('set')->once()->with($resourceSubKey1, ['other' => true], CACHE_TTL_MGR)->andReturn(true);
    // Delete the second resource's list (now empty)
    $this->cache->shouldReceive('deleteMultiple')->once()->with([$resourceSubKey2])->andReturn(true);
    // Delete the client's subscription list
    $this->cache->shouldReceive('delete')->once()->with($clientSubKey)->andReturn(true);

    $this->stateManager->removeAllResourceSubscriptions(TEST_CLIENT_ID_MGR);
});

// --- Message Queue ---
test('queueMessage logs warning if cache unavailable', function () {
    $this->logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Cannot queue message.*cache not available/'), Mockery::any());
    $this->stateManagerNoCache->queueMessage(TEST_CLIENT_ID_MGR, new Notification('2.0', 'm'));
});

test('queueMessage adds single message to cache', function () {
    $messageKey = getMgrCacheKey('messages', TEST_CLIENT_ID_MGR);
    $notification = new Notification('2.0', 'test/event', ['data' => 1]);

    $this->cache->shouldReceive('get')->once()->with($messageKey, [])->andReturn([]); // Start empty
    $this->cache->shouldReceive('set')->once()->with($messageKey, [$notification->toArray()], CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->queueMessage(TEST_CLIENT_ID_MGR, $notification);
});

test('queueMessage appends to existing messages in cache', function () {
    $messageKey = getMgrCacheKey('messages', TEST_CLIENT_ID_MGR);
    $existingMsg = ['jsonrpc' => '2.0', 'method' => 'existing'];
    $notification = new Notification('2.0', 'test/event', ['data' => 2]);

    $this->cache->shouldReceive('get')->once()->with($messageKey, [])->andReturn([$existingMsg]); // Start with one message
    $this->cache->shouldReceive('set')->once()->with($messageKey, [$existingMsg, $notification->toArray()], CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->queueMessage(TEST_CLIENT_ID_MGR, $notification);
});

test('queueMessage handles array of messages', function () {
    $messageKey = getMgrCacheKey('messages', TEST_CLIENT_ID_MGR);
    $notification1 = new Notification('2.0', 'msg1');
    $notification2 = new Notification('2.0', 'msg2');
    $messages = [$notification1, $notification2];
    $expectedData = [$notification1->toArray(), $notification2->toArray()];

    $this->cache->shouldReceive('get')->once()->with($messageKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->with($messageKey, $expectedData, CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->queueMessage(TEST_CLIENT_ID_MGR, $messages);
});

test('getQueuedMessages returns empty array if cache unavailable', function () {
    expect($this->stateManagerNoCache->getQueuedMessages(TEST_CLIENT_ID_MGR))->toBe([]);
});

test('getQueuedMessages retrieves and deletes messages from cache', function () {
    $messageKey = getMgrCacheKey('messages', TEST_CLIENT_ID_MGR);
    $messagesData = [['method' => 'msg1'], ['method' => 'msg2']];

    $this->cache->shouldReceive('get')->once()->with($messageKey, [])->andReturn($messagesData);
    $this->cache->shouldReceive('delete')->once()->with($messageKey)->andReturn(true);

    $retrieved = $this->stateManager->getQueuedMessages(TEST_CLIENT_ID_MGR);
    expect($retrieved)->toEqual($messagesData);

    // Verify cache is now empty
    $this->cache->shouldReceive('get')->once()->with($messageKey, [])->andReturn([]);
    expect($this->stateManager->getQueuedMessages(TEST_CLIENT_ID_MGR))->toBe([]);
});

// --- Client Management ---
test('cleanupClient logs warning if cache unavailable', function () {
    $this->logger->shouldReceive('warning')->once()->with(Mockery::pattern('/Cannot perform full client cleanup.*cache not available/'), Mockery::any());
    $this->stateManagerNoCache->cleanupClient(TEST_CLIENT_ID_MGR);
});

test('cleanupClient removes client data and optionally from active list', function ($removeFromActive) {
    $clientId = 'client-mgr-remove';
    $clientSubKey = getMgrCacheKey('client_subscriptions', $clientId);
    $activeKey = getMgrCacheKey('active_clients');
    $initialActive = [$clientId => time(), 'other' => time()];
    $keysToDelete = [
        getMgrCacheKey('initialized', $clientId), getMgrCacheKey('client_info', $clientId),
        getMgrCacheKey('protocol_version', $clientId), getMgrCacheKey('messages', $clientId),
    ];

    // Assume no subs for simplicity
    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn([]);

    if ($removeFromActive) {
        $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn($initialActive);
        $this->cache->shouldReceive('set')->once()->with($activeKey, Mockery::on(fn ($arg) => ! isset($arg[$clientId])), CACHE_TTL_MGR)->andReturn(true);
    } else {
        $this->cache->shouldNotReceive('get')->with($activeKey, []);
        $this->cache->shouldNotReceive('set')->with($activeKey, Mockery::any(), Mockery::any());
    }

    $this->cache->shouldReceive('deleteMultiple')->once()->with(Mockery::on(function ($arg) use ($keysToDelete) {
        return is_array($arg) && empty(array_diff($keysToDelete, $arg)) && empty(array_diff($arg, $keysToDelete));
    }))->andReturn(true);

    $this->stateManager->cleanupClient($clientId, $removeFromActive);

})->with([
    'Remove From Active List' => [true],
    'Keep In Active List' => [false],
]);

test('updateClientActivity does nothing if cache unavailable', function () {
    $this->cache->shouldNotReceive('get');
    $this->cache->shouldNotReceive('set');
    $this->stateManagerNoCache->updateClientActivity(TEST_CLIENT_ID_MGR);
});

test('updateClientActivity updates timestamp in cache', function () {
    $activeKey = getMgrCacheKey('active_clients');
    $startTime = time();

    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn(['other' => $startTime - 10]);
    $this->cache->shouldReceive('set')->once()->with($activeKey, Mockery::on(function ($arg) use ($startTime) {
        return isset($arg[TEST_CLIENT_ID_MGR]) && $arg[TEST_CLIENT_ID_MGR] >= $startTime;
    }), CACHE_TTL_MGR)->andReturn(true);

    $this->stateManager->updateClientActivity(TEST_CLIENT_ID_MGR);
});

test('getActiveClients returns empty array if cache unavailable', function () {
    expect($this->stateManagerNoCache->getActiveClients())->toBe([]);
});

test('getActiveClients filters inactive and cleans up', function () {
    $activeKey = getMgrCacheKey('active_clients');
    $clientActive1 = 'client-mgr-active1';
    $clientActive2 = 'client-mgr-active2';
    $clientInactive = 'client-mgr-inactive';
    $clientInvalidTs = 'client-mgr-invalid-ts';
    $now = time();
    $activeClientsData = [
        $clientActive1 => $now - 10,
        $clientActive2 => $now - CACHE_TTL_MGR + 10, // Still active relative to default threshold
        $clientInactive => $now - CACHE_TTL_MGR - 1, // Inactive
        $clientInvalidTs => 'not-a-timestamp', // Invalid data
    ];
    $expectedFinalActiveList = [
        $clientActive1 => $activeClientsData[$clientActive1],
        $clientActive2 => $activeClientsData[$clientActive2],
    ];

    // 1. Initial get for filtering
    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn($activeClientsData);
    // 2. Set the filtered list back (inactive and invalid removed)
    $this->cache->shouldReceive('set')->once()->with($activeKey, $expectedFinalActiveList, CACHE_TTL_MGR)->andReturn(true);
    // 3. Cleanup for inactive client
    $this->cache->shouldReceive('get')->once()->with(getMgrCacheKey('client_subscriptions', $clientInactive), [])->andReturn([]);
    $this->cache->shouldReceive('deleteMultiple')->once()->with(Mockery::on(fn ($keys) => in_array(getMgrCacheKey('initialized', $clientInactive), $keys)))->andReturn(true);
    // 4. Cleanup for client with invalid timestamp
    $this->cache->shouldReceive('get')->once()->with(getMgrCacheKey('client_subscriptions', $clientInvalidTs), [])->andReturn([]);
    $this->cache->shouldReceive('deleteMultiple')->once()->with(Mockery::on(fn ($keys) => in_array(getMgrCacheKey('initialized', $clientInvalidTs), $keys)))->andReturn(true);

    $active = $this->stateManager->getActiveClients(CACHE_TTL_MGR); // Use TTL as threshold for testing

    expect($active)->toEqualCanonicalizing([$clientActive1, $clientActive2]);
});

test('getLastActivityTime returns null if cache unavailable', function () {
    expect($this->stateManagerNoCache->getLastActivityTime(TEST_CLIENT_ID_MGR))->toBeNull();
});

test('getLastActivityTime returns timestamp or null from cache', function () {
    $activeKey = getMgrCacheKey('active_clients');
    $now = time();
    $cacheData = [TEST_CLIENT_ID_MGR => $now - 50, 'other' => $now - 100];

    $this->cache->shouldReceive('get')->with($activeKey, [])->times(3)->andReturn($cacheData);

    expect($this->stateManager->getLastActivityTime(TEST_CLIENT_ID_MGR))->toBe($now - 50);
    expect($this->stateManager->getLastActivityTime('other'))->toBe($now - 100);
    expect($this->stateManager->getLastActivityTime('nonexistent'))->toBeNull();
});
