<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use PhpMcp\Server\Contracts\SessionInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SessionHandlerInterface;

class SessionManager implements EventEmitterInterface
{
    use EventEmitterTrait;

    protected ?TimerInterface $gcTimer = null;
    protected int $gcInterval = 300; // 5 minutes

    public function __construct(
        protected SessionHandlerInterface $handler,
        protected LoggerInterface $logger,
        protected ?LoopInterface $loop = null,
        protected int $ttl = 3600
    ) {
        $this->loop ??= Loop::get();
    }

    /**
     * Start the garbage collection timer
     */
    public function startGcTimer(): void
    {
        if ($this->gcTimer !== null) {
            return;
        }

        $this->gcTimer = $this->loop->addPeriodicTimer($this->gcInterval, function () {
            $count = $this->handler->gc($this->ttl);
            $this->logger->debug('Session garbage collection complete', ['purged_sessions' => $count]);
        });
    }

    /**
     * Stop the garbage collection timer
     */
    public function stopGcTimer(): void
    {
        if ($this->gcTimer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->gcTimer);
            $this->gcTimer = null;
        }
    }

    /**
     * Create a new session
     */
    public function createSession(string $sessionId): SessionInterface
    {
        $session = new Session($this->handler, $sessionId);

        $session->hydrate([
            'initialized' => false,
            'client_info' => null,
            'protocol_version' => null,
            'subscriptions' => [],      // [uri => true]
            'message_queue' => [],      // string[] (raw JSON-RPC frames)
            'log_level' => null,
        ]);

        $session->save();

        $this->logger->info('Session created', ['sessionId' => $sessionId]);
        $this->emit('session_created', [$sessionId, $session]);

        return $session;
    }

    /**
     * Get an existing session
     */
    public function getSession(string $sessionId): ?SessionInterface
    {
        $session = new Session($this->handler, $sessionId);

        if (empty($session->all())) {
            return null;
        }

        return $session;
    }

    /**
     * Delete a session completely
     */
    public function deleteSession(string $sessionId): bool
    {
        $success = $this->handler->destroy($sessionId);

        if ($success) {
            $this->emit('session_deleted', [$sessionId]);
            $this->logger->info('Session deleted', ['sessionId' => $sessionId]);
        } else {
            $this->logger->warning('Failed to delete session', ['sessionId' => $sessionId]);
        }

        return $success;
    }

    public function queueMessage(string $sessionId, string $message): void
    {
        $session = $this->getSession($sessionId);
        if ($session === null) return;

        $session->queueMessage($message);
        $session->save();
    }

    public function dequeueMessages(string $sessionId): array
    {
        $session = $this->getSession($sessionId);
        if ($session === null) return [];

        $messages = $session->dequeueMessages();
        $session->save();

        return $messages;
    }

    public function hasQueuedMessages(string $sessionId): bool
    {
        $session = $this->getSession($sessionId, true);
        if ($session === null) return false;

        return $session->hasQueuedMessages();
    }
}
