<?php

namespace PhpMcp\Server\Tests\Unit\Session;

use Mockery;
use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Session\Session;

const SESSION_ID_SESS = 'test-session-obj-id';

beforeEach(function () {
    $this->sessionHandler = Mockery::mock(SessionHandlerInterface::class);
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_SESS)->once()->andReturn(false)->byDefault();
});

it('implements SessionInterface', function () {
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session)->toBeInstanceOf(SessionInterface::class);
});

// --- Constructor and ID Generation ---
it('uses provided ID if given', function () {
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_SESS)->once()->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->getId())->toBe(SESSION_ID_SESS);
});

it('generates an ID if none is provided', function () {
    $this->sessionHandler->shouldReceive('read')->with(Mockery::type('string'))->once()->andReturn(false);
    $session = new Session($this->sessionHandler);
    expect($session->getId())->toBeString()->toHaveLength(32);
});

it('loads data from handler on construction if session exists', function () {
    $initialData = ['foo' => 'bar', 'count' => 5, 'nested' => ['value' => true]];
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_SESS)->once()->andReturn(json_encode($initialData));

    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->all())->toEqual($initialData);
    expect($session->get('foo'))->toBe('bar');
});

it('initializes with empty data if handler read returns false', function () {
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_SESS)->once()->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->all())->toBeEmpty();
});

it('initializes with empty data if handler read returns invalid JSON', function () {
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_SESS)->once()->andReturn('this is not json');
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->all())->toBeEmpty();
});

it('saves current data to handler', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->set('name', 'Alice');
    $session->set('level', 10);

    $expectedSavedData = json_encode(['name' => 'Alice', 'level' => 10]);
    $this->sessionHandler->shouldReceive('write')->with(SESSION_ID_SESS, $expectedSavedData)->once()->andReturn(true);

    $session->save();
});

it('sets and gets a top-level attribute', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->set('name', 'Bob');
    expect($session->get('name'))->toBe('Bob');
    expect($session->has('name'))->toBeTrue();
});

it('gets default value if attribute does not exist', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->get('nonexistent', 'default_val'))->toBe('default_val');
    expect($session->has('nonexistent'))->toBeFalse();
});

it('sets and gets nested attributes using dot notation', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->set('user.profile.email', 'test@example.com');
    $session->set('user.profile.active', true);
    $session->set('user.roles', ['admin', 'editor']);

    expect($session->get('user.profile'))->toEqual(['email' => 'test@example.com', 'active' => true]);
    expect($session->get('user.roles'))->toEqual(['admin', 'editor']);
    expect($session->has('user.profile.email'))->toBeTrue();
    expect($session->has('user.other_profile.settings'))->toBeFalse();
});

it('set does not overwrite if overwrite is false and key exists', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->set('counter', 10);
    $session->set('counter', 20, false);
    expect($session->get('counter'))->toBe(10);

    $session->set('user.id', 1);
    $session->set('user.id', 2, false);
    expect($session->get('user.id'))->toBe(1);
});

it('set overwrites if overwrite is true (default)', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->set('counter', 10);
    $session->set('counter', 20);
    expect($session->get('counter'))->toBe(20);
});


it('forgets a top-level attribute', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode(['name' => 'Alice', 'age' => 30]));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->forget('age');
    expect($session->has('age'))->toBeFalse();
    expect($session->has('name'))->toBeTrue();
    expect($session->all())->toEqual(['name' => 'Alice']);
});

it('forgets a nested attribute using dot notation', function () {
    $initialData = ['user' => ['profile' => ['email' => 'test@example.com', 'status' => 'active'], 'id' => 1]];
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode($initialData));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);

    $session->forget('user.profile.status');
    expect($session->has('user.profile.status'))->toBeFalse();
    expect($session->has('user.profile.email'))->toBeTrue();
    expect($session->get('user.profile'))->toEqual(['email' => 'test@example.com']);

    $session->forget('user.profile');
    expect($session->has('user.profile'))->toBeFalse();
    expect($session->get('user'))->toEqual(['id' => 1]);
});

it('forget does nothing if key does not exist', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode(['name' => 'Test']));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->forget('nonexistent');
    $session->forget('another_nonexistent');
    expect($session->all())->toEqual(['name' => 'Test']);
});

it('pulls an attribute (gets and forgets)', function () {
    $initialData = ['item' => 'important', 'user' => ['token' => 'abc123xyz']];
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode($initialData));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);

    $pulledItem = $session->pull('item', 'default');
    expect($pulledItem)->toBe('important');
    expect($session->has('item'))->toBeFalse();

    $pulledToken = $session->pull('user.token');
    expect($pulledToken)->toBe('abc123xyz');
    expect($session->has('user.token'))->toBeFalse();
    expect($session->has('user'))->toBeTrue();

    $pulledNonExistent = $session->pull('nonexistent', 'fallback');
    expect($pulledNonExistent)->toBe('fallback');
});

it('clears all session data', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode(['a' => 1, 'b' => 2]));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $session->clear();
    expect($session->all())->toBeEmpty();
});

it('returns all data with all()', function () {
    $data = ['a' => 1, 'b' => ['c' => 3]];
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode($data));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->all())->toEqual($data);
});

it('hydrates session data, merging with defaults and removing id', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $newAttributes = [
        'client_info' => ['name' => 'TestClient', 'version' => '1.1'],
        'protocol_version' => '2024-custom',
        'user_custom_key' => 'my_value',
        'id' => 'should_be_ignored_on_hydrate'
    ];
    $session->hydrate($newAttributes);

    $allData = $session->all();
    expect($allData['initialized'])->toBeFalse();
    expect($allData['client_info'])->toEqual(['name' => 'TestClient', 'version' => '1.1']);
    expect($allData['protocol_version'])->toBe('2024-custom');
    expect($allData['message_queue'])->toEqual([]);
    expect($allData['log_level'])->toBeNull();
    expect($allData['user_custom_key'])->toBe('my_value');
    expect($allData)->not->toHaveKey('id');
});

it('queues messages correctly', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect($session->hasQueuedMessages())->toBeFalse();

    $msg1 = '{"jsonrpc":"2.0","method":"n1"}';
    $msg2 = '{"jsonrpc":"2.0","method":"n2"}';
    $session->queueMessage($msg1);
    $session->queueMessage($msg2);

    expect($session->hasQueuedMessages())->toBeTrue();
    expect($session->get('message_queue'))->toEqual([$msg1, $msg2]);
});

it('dequeues messages and clears queue', function () {
    $this->sessionHandler->shouldReceive('read')->andReturn(false);
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    $msg1 = '{"id":1}';
    $msg2 = '{"id":2}';
    $session->queueMessage($msg1);
    $session->queueMessage($msg2);

    $dequeued = $session->dequeueMessages();
    expect($dequeued)->toEqual([$msg1, $msg2]);
    expect($session->hasQueuedMessages())->toBeFalse();
    expect($session->get('message_queue', 'not_found'))->toEqual([]);

    expect($session->dequeueMessages())->toEqual([]);
});

it('jsonSerializes to all session data', function () {
    $data = ['serialize' => 'me', 'nested' => ['ok' => true]];
    $this->sessionHandler->shouldReceive('read')->andReturn(json_encode($data));
    $session = new Session($this->sessionHandler, SESSION_ID_SESS);
    expect(json_encode($session))->toBe(json_encode($data));
});
