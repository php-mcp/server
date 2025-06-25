<?php

namespace PhpMcp\Server\Tests\Unit\Session;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Tests\Mocks\Clock\FixedClock;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

const SESSION_ID_MGR_1 = 'manager-session-1';
const SESSION_ID_MGR_2 = 'manager-session-2';
const DEFAULT_TTL_MGR = 3600;
const GC_INTERVAL_MGR = 5;

beforeEach(function () {
    /** @var MockInterface&SessionHandlerInterface $sessionHandler */
    $this->sessionHandler = Mockery::mock(SessionHandlerInterface::class);
    /** @var MockInterface&LoggerInterface $logger */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->loop = Loop::get();

    $this->sessionManager = new SessionManager(
        $this->sessionHandler,
        $this->logger,
        $this->loop,
        DEFAULT_TTL_MGR
    );

    $this->sessionHandler->shouldReceive('read')->with(Mockery::any())->andReturn(false)->byDefault();
    $this->sessionHandler->shouldReceive('write')->with(Mockery::any(), Mockery::any())->andReturn(true)->byDefault();
    $this->sessionHandler->shouldReceive('destroy')->with(Mockery::any())->andReturn(true)->byDefault();
    $this->sessionHandler->shouldReceive('gc')->with(Mockery::any())->andReturn([])->byDefault();
});

it('creates a new session with default hydrated values and saves it', function () {
    $this->sessionHandler->shouldReceive('write')
        ->with(SESSION_ID_MGR_1, Mockery::on(function ($dataJson) {
            $data = json_decode($dataJson, true);
            expect($data['initialized'])->toBeFalse();
            expect($data['client_info'])->toBeNull();
            expect($data['protocol_version'])->toBeNull();
            expect($data['subscriptions'])->toEqual([]);
            expect($data['message_queue'])->toEqual([]);
            expect($data['log_level'])->toBeNull();
            return true;
        }))->once()->andReturn(true);

    $sessionCreatedEmitted = false;
    $emittedSessionId = null;
    $emittedSessionObj = null;
    $this->sessionManager->on('session_created', function ($id, $session) use (&$sessionCreatedEmitted, &$emittedSessionId, &$emittedSessionObj) {
        $sessionCreatedEmitted = true;
        $emittedSessionId = $id;
        $emittedSessionObj = $session;
    });

    $session = $this->sessionManager->createSession(SESSION_ID_MGR_1);

    expect($session)->toBeInstanceOf(SessionInterface::class);
    expect($session->getId())->toBe(SESSION_ID_MGR_1);
    expect($session->get('initialized'))->toBeFalse();
    $this->logger->shouldHaveReceived('info')->with('Session created', ['sessionId' => SESSION_ID_MGR_1]);
    expect($sessionCreatedEmitted)->toBeTrue();
    expect($emittedSessionId)->toBe(SESSION_ID_MGR_1);
    expect($emittedSessionObj)->toBe($session);
});

it('gets an existing session if handler read returns data', function () {
    $existingData = ['user_id' => 123, 'initialized' => true];
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_MGR_1)->once()->andReturn(json_encode($existingData));

    $session = $this->sessionManager->getSession(SESSION_ID_MGR_1);
    expect($session)->toBeInstanceOf(SessionInterface::class);
    expect($session->getId())->toBe(SESSION_ID_MGR_1);
    expect($session->get('user_id'))->toBe(123);
});

it('returns null from getSession if session does not exist (handler read returns false)', function () {
    $this->sessionHandler->shouldReceive('read')->with('non-existent')->once()->andReturn(false);
    $session = $this->sessionManager->getSession('non-existent');
    expect($session)->toBeNull();
});

it('returns null from getSession if session data is empty after load', function () {
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_MGR_1)->once()->andReturn(false);
    $session = $this->sessionManager->getSession(SESSION_ID_MGR_1);
    expect($session)->toBeNull();
});


it('deletes a session successfully and emits event', function () {
    $this->sessionHandler->shouldReceive('destroy')->with(SESSION_ID_MGR_1)->once()->andReturn(true);

    $sessionDeletedEmitted = false;
    $emittedSessionId = null;
    $this->sessionManager->on('session_deleted', function ($id) use (&$sessionDeletedEmitted, &$emittedSessionId) {
        $sessionDeletedEmitted = true;
        $emittedSessionId = $id;
    });

    $success = $this->sessionManager->deleteSession(SESSION_ID_MGR_1);

    expect($success)->toBeTrue();
    $this->logger->shouldHaveReceived('info')->with('Session deleted', ['sessionId' => SESSION_ID_MGR_1]);
    expect($sessionDeletedEmitted)->toBeTrue();
    expect($emittedSessionId)->toBe(SESSION_ID_MGR_1);
});

it('logs warning and does not emit event if deleteSession fails', function () {
    $this->sessionHandler->shouldReceive('destroy')->with(SESSION_ID_MGR_1)->once()->andReturn(false);
    $sessionDeletedEmitted = false;
    $this->sessionManager->on('session_deleted', function () use (&$sessionDeletedEmitted) {
        $sessionDeletedEmitted = true;
    });

    $success = $this->sessionManager->deleteSession(SESSION_ID_MGR_1);

    expect($success)->toBeFalse();
    $this->logger->shouldHaveReceived('warning')->with('Failed to delete session', ['sessionId' => SESSION_ID_MGR_1]);
    expect($sessionDeletedEmitted)->toBeFalse();
});

it('queues message for existing session', function () {
    $sessionData = ['message_queue' => []];
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_MGR_1)->andReturn(json_encode($sessionData));
    $message = '{"id":1}';

    $this->sessionHandler->shouldReceive('write')->with(SESSION_ID_MGR_1, Mockery::on(function ($dataJson) use ($message) {
        $data = json_decode($dataJson, true);
        expect($data['message_queue'])->toEqual([$message]);
        return true;
    }))->once()->andReturn(true);

    $this->sessionManager->queueMessage(SESSION_ID_MGR_1, $message);
});

it('does nothing on queueMessage if session does not exist', function () {
    $this->sessionHandler->shouldReceive('read')->with('no-such-session')->andReturn(false);
    $this->sessionHandler->shouldNotReceive('write');
    $this->sessionManager->queueMessage('no-such-session', '{"id":1}');
});

it('dequeues messages from existing session', function () {
    $messages = ['{"id":1}', '{"id":2}'];
    $sessionData = ['message_queue' => $messages];
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_MGR_1)->andReturn(json_encode($sessionData));
    $this->sessionHandler->shouldReceive('write')->with(SESSION_ID_MGR_1, Mockery::on(function ($dataJson) {
        $data = json_decode($dataJson, true);
        expect($data['message_queue'])->toEqual([]);
        return true;
    }))->once()->andReturn(true);

    $dequeued = $this->sessionManager->dequeueMessages(SESSION_ID_MGR_1);
    expect($dequeued)->toEqual($messages);
});

it('returns empty array from dequeueMessages if session does not exist', function () {
    $this->sessionHandler->shouldReceive('read')->with('no-such-session')->andReturn(false);
    expect($this->sessionManager->dequeueMessages('no-such-session'))->toBe([]);
});

it('checks hasQueuedMessages for existing session', function () {
    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_MGR_1)->andReturn(json_encode(['message_queue' => ['msg']]));
    expect($this->sessionManager->hasQueuedMessages(SESSION_ID_MGR_1))->toBeTrue();

    $this->sessionHandler->shouldReceive('read')->with(SESSION_ID_MGR_2)->andReturn(json_encode(['message_queue' => []]));
    expect($this->sessionManager->hasQueuedMessages(SESSION_ID_MGR_2))->toBeFalse();
});

it('returns false from hasQueuedMessages if session does not exist', function () {
    $this->sessionHandler->shouldReceive('read')->with('no-such-session')->andReturn(false);
    expect($this->sessionManager->hasQueuedMessages('no-such-session'))->toBeFalse();
});

it('can stop GC timer on stopGcTimer ', function () {
    $loop = Mockery::mock(LoopInterface::class);
    $loop->shouldReceive('addPeriodicTimer')->with(Mockery::any(), Mockery::type('callable'))->once()->andReturn(Mockery::mock(TimerInterface::class));
    $loop->shouldReceive('cancelTimer')->with(Mockery::type(TimerInterface::class))->once();

    $manager = new SessionManager($this->sessionHandler, $this->logger, $loop);
    $manager->startGcTimer();
    $manager->stopGcTimer();
});

it('GC timer callback deletes expired sessions', function () {
    $clock = new FixedClock();

    $sessionHandler = new ArraySessionHandler(60, $clock);
    $sessionHandler->write('sess_expired', 'data');

    // $clock->addSeconds(100);

    $manager = new SessionManager(
        $sessionHandler,
        $this->logger,
        ttl: 30,
        gcInterval: 0.01
    );

    $session = $manager->getSession('sess_expired');
    expect($session)->toBeNull();
});


it('does not start GC timer if already started', function () {
    $this->loop = Mockery::mock(LoopInterface::class);
    $this->loop->shouldReceive('addPeriodicTimer')->once()->andReturn(Mockery::mock(TimerInterface::class));

    $manager = new SessionManager($this->sessionHandler, $this->logger, $this->loop);
    $manager->startGcTimer();
});
