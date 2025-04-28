<?php

namespace PhpMcp\Server\Transports;

use PhpMcp\Server\Processor;
use PhpMcp\Server\State\TransportState;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Stream\WritableStreamInterface;
use Throwable;

/**
 * Integrates MCP HTTP+SSE logic with a ReactPHP event loop and streams.
 * Uses the core HttpTransportHandler for underlying MCP processing.
 */
class ReactPhpHttpTransportHandler extends HttpTransportHandler
{
    /**
     * Client timeout in seconds (5 minutes)
     */
    private const INACTIVITY_TIMEOUT = 300.0;

    /**
     * How often to check for inactive clients
     */
    private const CLEANUP_CHECK_INTERVAL = 60.0;

    /**
     * Store timers per client [clientId => [timer1, timer2, ...]]
     */
    private array $clientTimers = [];

    /**
     * Store SSE streams per client [clientId => stream]
     *
     * @var array<string, WritableStreamInterface>
     */
    private array $clientSseStreams = [];

    public function __construct(
        private readonly Processor $processor,
        private readonly TransportState $transportState,
        private readonly LoggerInterface $logger,
        private readonly LoopInterface $loop,
    ) {
        parent::__construct($processor, $transportState, $logger);

        $this->startGlobalCleanupTimer();
    }

    public function sendResponse(string $data, string $clientId): void
    {
        $stream = $this->getClientSseStream($clientId);

        $stream->write($data);

        $this->logger->debug('ReactPHP MCP: Sent response', ['data' => $data, 'client_id' => $clientId]);
    }

    public function getClientSseStream(string $clientId): WritableStreamInterface
    {
        return $this->clientSseStreams[$clientId];
    }

    public function setClientSseStream(string $clientId, WritableStreamInterface $stream): void
    {
        $this->clientSseStreams[$clientId] = $stream;
    }

    public function handleSseConnection(
        string $clientId,
        string $postEndpointUri,
        float $loopInterval = 0.05,  // 50ms
        float $activityUpdateInterval = 60.0,
    ): void {
        $this->logger->info('ReactPHP MCP: Starting SSE stream', ['client_id' => $clientId]);

        $stream = $this->getClientSseStream($clientId);
        if (! $stream->isWritable()) {
            $this->logger->warning('ReactPHP MCP: Stream not writable on start.', ['client_id' => $clientId]);

            return;
        }

        // 1. Send initial endpoint event (with a tiny delay)
        $this->loop->addTimer(0.01, function () use ($clientId, $postEndpointUri) {
            $stream = $this->getClientSseStream($clientId);
            if (! $stream->isWritable()) {
                $this->logger->warning('ReactPHP MCP: Stream closed before initial endpoint could be sent.', ['client_id' => $clientId]);

                return;
            }

            $this->sendSseEvent($clientId, 'endpoint', $postEndpointUri, null);
        });

        // 2. Setup periodic timers
        $this->clientTimers[$clientId] = [];
        $eventId = 1;

        // Timer to pull and send queued messages
        $messageTimer = $this->loop->addPeriodicTimer($loopInterval, function () use ($clientId, &$eventId) {
            $stream = $this->getClientSseStream($clientId);
            if (! $stream->isWritable()) {
                $this->cleanupClient($clientId);

                return;
            }

            try {
                $messages = $this->transportState->getQueuedMessages($clientId);

                foreach ($messages as $message) {
                    $messageData = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $this->sendSseEvent($clientId, 'message', $messageData, (string) $eventId++);
                }
            } catch (Throwable $e) {
                $this->logger->error('ReactPHP MCP: Error processing/sending message queue.', ['client_id' => $clientId, 'exception' => $e->getMessage()]);
                // Decide if we should close the stream on error
                $this->cleanupClient($clientId);
                $stream->close();
            }
        });
        $this->clientTimers[$clientId][] = $messageTimer;

        // Timer to update client activity timestamp
        $activityTimer = $this->loop->addPeriodicTimer($activityUpdateInterval, function () use ($clientId) {
            try {
                $this->transportState->updateClientActivity($clientId);
                $this->logger->debug('ReactPHP MCP: Updated client activity', ['client_id' => $clientId]);
            } catch (Throwable $e) {
                $this->logger->error('ReactPHP MCP: Error updating client activity.', ['client_id' => $clientId, 'exception' => $e->getMessage()]);
            }
        });
        $this->clientTimers[$clientId][] = $activityTimer;

        // 3. Setup stream close handler
        $stream->on('close', function () use ($clientId) {
            $this->logger->info('ReactPHP MCP: Stream closed by client or error.', ['client_id' => $clientId]);
            $this->cleanupClient($clientId); // Ensure cleanup happens
        });

        $stream->on('error', function (Throwable $error) use ($clientId) {
            $this->logger->error('ReactPHP MCP: Stream error.', ['client_id' => $clientId, 'exception' => $error->getMessage()]);
            $this->cleanupClient($clientId); // Ensure cleanup happens
        });
    }

    /**
     * Cleans up resources for a client (cancels timers, delegates to core handler).
     */
    public function cleanupClient(string $clientId): void
    {
        parent::cleanupClient($clientId);

        if (isset($this->clientTimers[$clientId])) {
            $this->logger->debug('ReactPHP MCP: Cancelling timers', ['client_id' => $clientId]);
            foreach ($this->clientTimers[$clientId] as $timer) {
                $this->loop->cancelTimer($timer);
            }
            unset($this->clientTimers[$clientId]);
        } else {
            $this->logger->debug('ReactPHP MCP: No timers found to cancel for client', ['client_id' => $clientId]);
        }

        if (isset($this->clientSseStreams[$clientId])) {
            $this->clientSseStreams[$clientId]->close();
            unset($this->clientSseStreams[$clientId]);
        }
    }

    /**
     * Starts a single timer that periodically checks all managed clients for inactivity.
     */
    private function startGlobalCleanupTimer(): void
    {
        $this->loop->addPeriodicTimer(self::CLEANUP_CHECK_INTERVAL, function () {
            $now = microtime(true);
            $activeClientIds = array_keys($this->clientTimers); // Get IDs managed by *this* handler
            $state = $this->transportState;

            if (empty($activeClientIds)) {
                $this->logger->debug('ReactPHP MCP: Inactivity check running, no active clients managed by this handler.');

                return;
            }

            $this->logger->debug('ReactPHP MCP: Running inactivity check...', ['managed_clients' => count($activeClientIds)]);

            foreach ($activeClientIds as $clientId) {
                $lastActivity = $state->getLastActivityTime($clientId);

                // If lastActivity is null, maybe the client never fully initialized or state was lost
                // Treat it as inactive after a grace period?
                // For now, only clean up if we have a timestamp and it's too old.
                if ($lastActivity !== null && ($now - $lastActivity > self::INACTIVITY_TIMEOUT)) {
                    $this->logger->warning(
                        'ReactPHP MCP: Client inactive for too long. Cleaning up.',
                        [
                            'client_id' => $clientId,
                            'last_activity' => date('Y-m-d H:i:s', (int) $lastActivity),
                            'timeout_seconds' => self::INACTIVITY_TIMEOUT,
                        ]
                    );
                    // cleanupClient will cancel the timers and remove the ID from $this->clientTimers
                    $this->cleanupClient($clientId);
                }
            }
        });
    }
}
