<?php

use Mockery; // Use ArrayCache for simple testing
use Mockery\MockInterface;
use PhpMcp\Server\Defaults\ArrayCache;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\State\TransportState;
use Psr\Log\LoggerInterface; // Import Mockery
use Psr\SimpleCache\CacheInterface; // Import MockInterface for type hinting if needed

const TEST_CLIENT_ID = 'test-client-123';
const TEST_URI = 'file:///test.txt';
const TEST_URI_2 = 'config://app';
const CACHE_PREFIX = 'mcp_test_';
const CACHE_TTL = 600; // Example TTL

// Mocks and SUT instance
beforeEach(function () {
    // Use Mockery explicitly
    $this->cache = Mockery::mock(CacheInterface::class);
    /** @var MockInterface&LoggerInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(); // Ignore log calls unless specified

    $this->transportState = new TransportState(
        $this->cache,
        $this->logger,
        CACHE_PREFIX,
        CACHE_TTL
    );
});

// Add Mockery close after each test to verify expectations and clean up
afterEach(function () {
    Mockery::close();
});

// Helper to generate expected cache keys
function getCacheKey(string $key, ?string $clientId = null): string
{
    return $clientId ? CACHE_PREFIX."{$key}_{$clientId}" : CACHE_PREFIX.$key;
}

// --- Tests ---

test('can check if client is initialized', function () {
    // Arrange
    $initializedKey = getCacheKey('initialized', TEST_CLIENT_ID);
    $this->cache->shouldReceive('get')->once()->with($initializedKey, false)->andReturn(false);
    $this->cache->shouldReceive('get')->once()->with($initializedKey, false)->andReturn(true);

    // Act & Assert
    expect($this->transportState->isInitialized(TEST_CLIENT_ID))->toBeFalse();
    expect($this->transportState->isInitialized(TEST_CLIENT_ID))->toBeTrue();
});

test('can mark client as initialized', function () {
    // Arrange
    $initializedKey = getCacheKey('initialized', TEST_CLIENT_ID);
    $activityKey = getCacheKey('active_clients');

    $this->cache->shouldReceive('set')->once()
        ->with($initializedKey, true, CACHE_TTL)
        ->andReturn(true);
    // Expect updateClientActivity call
    $this->cache->shouldReceive('get')->once()->with($activityKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()
        ->with($activityKey, Mockery::type('array'), CACHE_TTL) // Use Mockery namespace
        ->andReturnUsing(function ($key, $value) {
            expect($value)->toHaveKey(TEST_CLIENT_ID);

            return true;
        });

    // Act
    $this->transportState->markInitialized(TEST_CLIENT_ID);

    // Assert (implicit via mock expectations)
});

test('can store and retrieve client info', function () {
    // Arrange
    $clientInfoKey = getCacheKey('client_info', TEST_CLIENT_ID);
    $protocolKey = getCacheKey('protocol_version', TEST_CLIENT_ID);
    $clientInfo = ['name' => 'TestClient', 'version' => '1.1'];
    $protocolVersion = '2024-11-05';

    $this->cache->shouldReceive('set')->once()
        ->with($clientInfoKey, $clientInfo, CACHE_TTL)
        ->andReturn(true);
    $this->cache->shouldReceive('set')->once()
        ->with($protocolKey, $protocolVersion, CACHE_TTL)
        ->andReturn(true);

    $this->cache->shouldReceive('get')->once()
        ->with($clientInfoKey)
        ->andReturn($clientInfo);
    $this->cache->shouldReceive('get')->once()
        ->with($protocolKey)
        ->andReturn($protocolVersion);

    // Act
    $this->transportState->storeClientInfo($clientInfo, $protocolVersion, TEST_CLIENT_ID);
    $retrievedInfo = $this->transportState->getClientInfo(TEST_CLIENT_ID);
    $retrievedVersion = $this->transportState->getProtocolVersion(TEST_CLIENT_ID);

    // Assert
    expect($retrievedInfo)->toBe($clientInfo);
    expect($retrievedVersion)->toBe($protocolVersion);
});

test('can add resource subscription', function () {
    // Arrange
    $clientSubKey = getCacheKey('client_subscriptions', TEST_CLIENT_ID);
    $resourceSubKey = getCacheKey('resource_subscriptions', TEST_URI);

    // Mock initial gets (empty)
    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn([]);
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey, [])->andReturn([]);

    // Mock sets
    $this->cache->shouldReceive('set')->once()
        ->with($clientSubKey, [TEST_URI => true], CACHE_TTL)
        ->andReturn(true);
    $this->cache->shouldReceive('set')->once()
        ->with($resourceSubKey, [TEST_CLIENT_ID => true], CACHE_TTL)
        ->andReturn(true);

    // Act
    $this->transportState->addResourceSubscription(TEST_CLIENT_ID, TEST_URI);

    // Assert (implicit via mock expectations)
});

test('can check resource subscription status', function () {
    // Arrange
    $clientSubKey = getCacheKey('client_subscriptions', TEST_CLIENT_ID);
    $this->cache->shouldReceive('get')
        ->with($clientSubKey, [])
        ->andReturn([TEST_URI => true]); // Client is subscribed

    // Act & Assert
    expect($this->transportState->isSubscribedToResource(TEST_CLIENT_ID, TEST_URI))->toBeTrue();
    expect($this->transportState->isSubscribedToResource(TEST_CLIENT_ID, TEST_URI_2))->toBeFalse(); // Check for unsubscribed
});

test('can get resource subscribers', function () {
    // Arrange
    $resourceSubKey = getCacheKey('resource_subscriptions', TEST_URI);
    $resourceSubKey2 = getCacheKey('resource_subscriptions', TEST_URI_2); // Key for the second URI

    $this->cache->shouldReceive('get')->once() // Expect call for TEST_URI
        ->with($resourceSubKey, [])
        ->andReturn([TEST_CLIENT_ID => true, 'other-client' => true]);
    $this->cache->shouldReceive('get')->once() // Expect call for TEST_URI_2
        ->with($resourceSubKey2, [])
        ->andReturn([]); // Assume empty for the second URI

    // Act
    $subscribers = $this->transportState->getResourceSubscribers(TEST_URI);

    // Assert
    expect($subscribers)->toEqualCanonicalizing([TEST_CLIENT_ID, 'other-client']); // Use toEqualCanonicalizing for order-independent array comparison
    expect($this->transportState->getResourceSubscribers(TEST_URI_2))->toBe([]); // Test non-subscribed resource
});

test('can remove resource subscription', function () {
    // Arrange
    $clientSubKey = getCacheKey('client_subscriptions', TEST_CLIENT_ID);
    $resourceSubKey = getCacheKey('resource_subscriptions', TEST_URI);
    $initialClientSubs = [TEST_URI => true, TEST_URI_2 => true];
    $initialResourceSubs = [TEST_CLIENT_ID => true, 'other-client' => true];

    // Mock initial gets
    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn($initialClientSubs);
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey, [])->andReturn($initialResourceSubs);

    // Mock sets for removal
    $this->cache->shouldReceive('set')->once()
        ->with($clientSubKey, [TEST_URI_2 => true], CACHE_TTL) // TEST_URI removed
        ->andReturn(true);
    $this->cache->shouldReceive('set')->once()
        ->with($resourceSubKey, ['other-client' => true], CACHE_TTL) // TEST_CLIENT_ID removed
        ->andReturn(true);

    // Act
    $this->transportState->removeResourceSubscription(TEST_CLIENT_ID, TEST_URI);

    // Assert (implicit via mock expectations)
});

test('can remove all resource subscriptions for a client', function () {
    // Arrange
    $clientSubKey = getCacheKey('client_subscriptions', TEST_CLIENT_ID);
    $resourceSubKey1 = getCacheKey('resource_subscriptions', TEST_URI);
    $resourceSubKey2 = getCacheKey('resource_subscriptions', TEST_URI_2);

    $initialClientSubs = [TEST_URI => true, TEST_URI_2 => true];
    $initialResourceSubs1 = [TEST_CLIENT_ID => true, 'other-client' => true];
    $initialResourceSubs2 = [TEST_CLIENT_ID => true]; // Only this client subscribed

    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn($initialClientSubs);
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey1, [])->andReturn($initialResourceSubs1);
    $this->cache->shouldReceive('get')->once()->with($resourceSubKey2, [])->andReturn($initialResourceSubs2);

    // Mock updates/deletes
    $this->cache->shouldReceive('set')->once() // Update first resource sub list
        ->with($resourceSubKey1, ['other-client' => true], CACHE_TTL)
        ->andReturn(true);
    $this->cache->shouldReceive('delete')->once() // Delete second resource sub list (now empty)
        ->with($resourceSubKey2)
        ->andReturn(true);
    $this->cache->shouldReceive('delete')->once() // Delete client sub list
        ->with($clientSubKey)
        ->andReturn(true);

    // Act
    $this->transportState->removeAllResourceSubscriptions(TEST_CLIENT_ID);

    // Assert (implicit via mock expectations)
});

test('can queue and retrieve messages', function () {
    // Arrange
    $messageKey = getCacheKey('messages', TEST_CLIENT_ID);
    // Fix: Notification constructor expects ('2.0', method, params)
    $notification = new Notification('2.0', 'test/event', ['data' => 1]);
    $response = Response::success(new EmptyResult, id: 1);

    // Mock initial get (empty), set, get, delete
    $this->cache->shouldReceive('get')->once()->ordered()->with($messageKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->ordered()
        ->with($messageKey, [$notification->toArray()], CACHE_TTL)
        ->andReturn(true);
    $this->cache->shouldReceive('get')->once()->ordered()->with($messageKey, [])->andReturn([$notification->toArray()]);
    $this->cache->shouldReceive('set')->once()->ordered()
        ->with($messageKey, [$notification->toArray(), $response->toArray()], CACHE_TTL)
        ->andReturn(true);

    // For getQueuedMessages
    $this->cache->shouldReceive('get')->once()->ordered()->with($messageKey, [])->andReturn([$notification->toArray(), $response->toArray()]);
    $this->cache->shouldReceive('delete')->once()->ordered()->with($messageKey)->andReturn(true);

    // Act
    $this->transportState->queueMessage(TEST_CLIENT_ID, $notification);
    $this->transportState->queueMessage(TEST_CLIENT_ID, $response); // Queue another
    $messages = $this->transportState->getQueuedMessages(TEST_CLIENT_ID);

    // Assert
    expect($messages)->toBeArray()->toHaveCount(2);
    expect($messages[0])->toBe($notification->toArray());
    expect($messages[1])->toBe($response->toArray());

    // Verify it's empty now
    $this->cache->shouldReceive('get')->once()->with($messageKey, [])->andReturn([]);
    expect($this->transportState->getQueuedMessages(TEST_CLIENT_ID))->toBe([]);
});

test('can queue message for all active clients', function () {
    // Arrange
    $activeKey = getCacheKey('active_clients');
    $client1 = 'client-1';
    $client2 = 'client-2';
    $inactiveClient = 'client-inactive';
    $now = time();
    $activeClientsData = [
        $client1 => $now - 10,
        $client2 => $now - 20,
        $inactiveClient => $now - 1000, // Should be filtered out by default threshold (300s)
    ];
    $expectedCleanedList = [
        $client1 => $activeClientsData[$client1],
        $client2 => $activeClientsData[$client2],
    ];
    $message = new Notification('2.0', 'global/event');
    $messageKey1 = getCacheKey('messages', $client1);
    $messageKey2 = getCacheKey('messages', $client2);

    $this->cache->shouldReceive('get')->once()->ordered()->with($activeKey, [])->andReturn($activeClientsData);
    $this->cache->shouldReceive('set')->once()->ordered()
        ->with($activeKey, $expectedCleanedList, CACHE_TTL)
        ->andReturn(true);

    // Mock queuing for client1
    $this->cache->shouldReceive('get')->once()->with($messageKey1, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->with($messageKey1, [$message->toArray()], CACHE_TTL)->andReturn(true);

    // Mock queuing for client2
    $this->cache->shouldReceive('get')->once()->with($messageKey2, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()->with($messageKey2, [$message->toArray()], CACHE_TTL)->andReturn(true);

    // Mock cleanupClient
    $this->cache->shouldReceive('get')->once()->with(getCacheKey('client_subscriptions', $inactiveClient), [])->andReturn([]);
    $this->cache->shouldReceive('deleteMultiple')->once()->with([
        getCacheKey('initialized', $inactiveClient),
        getCacheKey('client_info', $inactiveClient),
        getCacheKey('protocol_version', $inactiveClient),
        getCacheKey('messages', $inactiveClient),
        getCacheKey('client_subscriptions', $inactiveClient),
    ])->andReturn(true);

    // Act
    $this->transportState->queueMessageForAll($message);

    // Assert (implicit via mock expectations - inactiveClient is not called for queueing)
});

test('can update client activity', function () {
    // Arrange
    $activeKey = getCacheKey('active_clients');
    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn([]);
    $this->cache->shouldReceive('set')->once()
        ->with($activeKey, Mockery::on(function ($arg) { // Use Mockery namespace
            return is_array($arg) && isset($arg[TEST_CLIENT_ID]) && is_int($arg[TEST_CLIENT_ID]);
        }), CACHE_TTL)
        ->andReturn(true);

    // Act
    $this->transportState->updateClientActivity(TEST_CLIENT_ID);

    // Assert (implicit)
});

test('can get active clients filtering inactive ones', function () {
    // Arrange
    $activeKey = getCacheKey('active_clients');
    $client1 = 'client-1';
    $client2 = 'client-2';
    $inactiveClient = 'client-inactive';
    $now = time();
    $activeClientsData = [
        $client1 => $now - 10, // Active
        $client2 => $now - 299, // Active (just within default 300s threshold)
        $inactiveClient => $now - 301, // Inactive
    ];
    $expectedCleanedList = [
        $client1 => $activeClientsData[$client1],
        $client2 => $activeClientsData[$client2],
    ];

    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn($activeClientsData);
    $this->cache->shouldReceive('set')->once()
        ->with($activeKey, $expectedCleanedList, CACHE_TTL)
        ->andReturn(true);

    // Mock cleanupClient
    $this->cache->shouldReceive('get')->once()->with(getCacheKey('client_subscriptions', $inactiveClient), [])->andReturn([]);
    $this->cache->shouldReceive('deleteMultiple')->once()->with([
        getCacheKey('initialized', $inactiveClient),
        getCacheKey('client_info', $inactiveClient),
        getCacheKey('protocol_version', $inactiveClient),
        getCacheKey('messages', $inactiveClient),
        getCacheKey('client_subscriptions', $inactiveClient),
    ])->andReturn(true);

    // Act
    $activeClients = $this->transportState->getActiveClients(); // Use default threshold
    // Need to mock the get/set again for the second call with different threshold
    $activeClientsDataLow = [
        $client1 => $now - 10, // Active
        $inactiveClient => $now - 301, // Inactive
    ];
    $expectedCleanedListLow = [
        $client1 => $activeClientsDataLow[$client1],
    ];
    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn($activeClientsDataLow);
    $this->cache->shouldReceive('set')->once()
        ->with($activeKey, $expectedCleanedListLow, CACHE_TTL)
        ->andReturn(true);

    // Mock cleanupClient
    $this->cache->shouldReceive('get')->once()->with(getCacheKey('client_subscriptions', $inactiveClient), [])->andReturn([]);
    $this->cache->shouldReceive('deleteMultiple')->once()->with([
        getCacheKey('initialized', $inactiveClient),
        getCacheKey('client_info', $inactiveClient),
        getCacheKey('protocol_version', $inactiveClient),
        getCacheKey('messages', $inactiveClient),
        getCacheKey('client_subscriptions', $inactiveClient),
    ])->andReturn(true);
    
    $activeClientsLowThreshold = $this->transportState->getActiveClients(50); // Use custom threshold

    // Assert
    expect($activeClients)->toEqualCanonicalizing([$client1, $client2]); // Use toEqualCanonicalizing
    expect($activeClientsLowThreshold)->toEqualCanonicalizing([$client1]); // Use toEqualCanonicalizing
});

test('can remove client', function () {
    // Arrange
    $clientId = 'client-to-remove';
    // Mock dependencies for removeAllResourceSubscriptions
    $clientSubKey = getCacheKey('client_subscriptions', $clientId);
    $this->cache->shouldReceive('get')->once()->with($clientSubKey, [])->andReturn([]); // Assume no subs for simplicity here

    // Mock active clients list
    $activeKey = getCacheKey('active_clients');
    $initialActive = [$clientId => time(), 'other-client' => time()];
    $this->cache->shouldReceive('get')->once()->with($activeKey, [])->andReturn($initialActive);
    // Use Mockery::on to be less strict about exact timestamp
    $this->cache->shouldReceive('set')->once()->with($activeKey, Mockery::on(function ($arg) {
        return is_array($arg) && ! isset($arg['client-to-remove']) && isset($arg['other-client']);
    }), CACHE_TTL)->andReturn(true);

    // Mock deletes for other keys
    $keysToDelete = [
        getCacheKey('initialized', $clientId),
        getCacheKey('client_info', $clientId),
        getCacheKey('protocol_version', $clientId),
        getCacheKey('messages', $clientId),
        $clientSubKey, // Add this key as it's deleted by deleteMultiple
    ];
    // Fix: Expect deleteMultiple instead of individual deletes
    $this->cache->shouldReceive('deleteMultiple')->once()
        ->with(Mockery::on(function ($arg) use ($keysToDelete) {
            // Check if the passed keys match the expected keys, order doesn't matter
            return is_array($arg) && empty(array_diff($keysToDelete, $arg)) && empty(array_diff($arg, $keysToDelete));
        }))
        ->andReturn(true);

    // Act
    $this->transportState->cleanupClient($clientId);

    // Assert (implicit)
});
