<?php

namespace PhpMcp\Server\Tests\Unit\State;

use PhpMcp\Server\State\ClientState;

const TEST_CLIENT_ID_FOR_STATE = 'test-client-state-id';

it('sets lastActivityTimestamp on construction', function () {
    $startTime = time();
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);
    $endTime = time();

    expect($state->lastActivityTimestamp)->toBeGreaterThanOrEqual($startTime);
    expect($state->lastActivityTimestamp)->toBeLessThanOrEqual($endTime);
});

it('has correct default property values', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);

    expect($state->isInitialized)->toBeFalse();
    expect($state->clientInfo)->toBeNull();
    expect($state->protocolVersion)->toBeNull();
    expect($state->subscriptions)->toBe([]);
    expect($state->messageQueue)->toBe([]);
    expect($state->requestedLogLevel)->toBeNull();
});

it('can add resource subscriptions for a client', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);
    $uri1 = 'file:///doc1.txt';
    $uri2 = 'config://app/settings';

    $state->addSubscription($uri1);
    expect($state->subscriptions)->toHaveKey($uri1);
    expect($state->subscriptions[$uri1])->toBeTrue();
    expect($state->subscriptions)->toHaveCount(1);

    $state->addSubscription($uri2);
    expect($state->subscriptions)->toHaveKey($uri2);
    expect($state->subscriptions[$uri2])->toBeTrue();
    expect($state->subscriptions)->toHaveCount(2);

    // Adding the same URI again should not change the count
    $state->addSubscription($uri1);
    expect($state->subscriptions)->toHaveCount(2);
});

it('can remove a resource subscription for a client', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);
    $uri1 = 'file:///doc1.txt';
    $uri2 = 'config://app/settings';

    $state->addSubscription($uri1);
    $state->addSubscription($uri2);
    expect($state->subscriptions)->toHaveCount(2);

    $state->removeSubscription($uri1);
    expect($state->subscriptions)->not->toHaveKey($uri1);
    expect($state->subscriptions)->toHaveKey($uri2);
    expect($state->subscriptions)->toHaveCount(1);

    // Removing a non-existent URI should not cause an error or change count
    $state->removeSubscription('nonexistent://uri');
    expect($state->subscriptions)->toHaveCount(1);

    $state->removeSubscription($uri2);
    expect($state->subscriptions)->toBeEmpty();
});

it('can clear all resource subscriptions for a client', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);
    $state->addSubscription('file:///doc1.txt');
    $state->addSubscription('config://app/settings');
    expect($state->subscriptions)->not->toBeEmpty();

    $state->clearSubscriptions();
    expect($state->subscriptions)->toBeEmpty();
});

// --- Message Queue Management ---

it('can add a message to the queue', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);
    $message1 = json_encode(['jsonrpc' => '2.0', 'method' => 'notify1']);
    $message2 = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

    $state->addMessageToQueue($message1);
    expect($state->messageQueue)->toHaveCount(1);
    expect($state->messageQueue[0])->toBe($message1);

    $state->addMessageToQueue($message2);
    expect($state->messageQueue)->toHaveCount(2);
    expect($state->messageQueue[1])->toBe($message2);
});

it('can consume all messages from the queue', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);
    $message1 = json_encode(['method' => 'msg1']);
    $message2 = json_encode(['method' => 'msg2']);

    $state->addMessageToQueue($message1);
    $state->addMessageToQueue($message2);
    expect($state->messageQueue)->toHaveCount(2);

    $consumedMessages = $state->consumeMessageQueue();
    expect($consumedMessages)->toBeArray()->toHaveCount(2);
    expect($consumedMessages[0])->toBe($message1);
    expect($consumedMessages[1])->toBe($message2);

    // Verify the queue is now empty
    expect($state->messageQueue)->toBeEmpty();
    expect($state->consumeMessageQueue())->toBeEmpty(); // Consuming an empty queue
});

test('public properties can be set and retain values', function () {
    $state = new ClientState(TEST_CLIENT_ID_FOR_STATE);

    $state->isInitialized = true;
    expect($state->isInitialized)->toBeTrue();

    $clientInfoData = ['name' => 'Test Client', 'version' => '0.9'];
    $state->clientInfo = $clientInfoData;
    expect($state->clientInfo)->toBe($clientInfoData);

    $protoVersion = '2024-11-05-test';
    $state->protocolVersion = $protoVersion;
    expect($state->protocolVersion)->toBe($protoVersion);

    $logLevel = 'debug';
    $state->requestedLogLevel = $logLevel;
    expect($state->requestedLogLevel)->toBe($logLevel);
});
