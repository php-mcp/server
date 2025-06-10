<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

use Evenement\EventEmitterInterface;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Schema\JsonRpc\Message;
use React\Promise\PromiseInterface;

/**
 * Interface for server-side MCP transports.
 *
 * Implementations handle listening for connections/data and sending raw messages.
 * MUST emit events for lifecycle and messages.
 *
 * --- Expected Emitted Events ---
 * 'ready': () - Optional: Fired when listening starts successfully.
 * 'client_connected': (string $sessionId) - New client connection
 * 'message': (Message $message, string $sessionId, array $context) - Complete message received from a client.
 * 'client_disconnected': (string $sessionId, ?string $reason) - Client connection closed.
 * 'error': (Throwable $error, ?string $sessionId) - Error occurred (general transport error if sessionId is null).
 * 'close': (?string $reason) - Transport listener stopped completely.
 */
interface ServerTransportInterface extends EventEmitterInterface
{
    /**
     * Starts the transport listener (e.g., listens on STDIN, starts HTTP server).
     * Does NOT run the event loop itself. Prepares transport to emit events when loop runs.
     *
     * @throws TransportException on immediate setup failure (e.g., port binding).
     */
    public function listen(): void;

    /**
     * Sends a message to a connected client session with optional context.
     *
     * @param  Message  $message  Message to send.
     * @param  string  $sessionId  Target session identifier.
     * @param  array  $context  Optional context for the message. Eg. streamId for SSE.
     * @return PromiseInterface<void> Resolves on successful send/queue, rejects on specific send error.
     */
    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface;

    /**
     * Stops the transport listener gracefully and closes all active connections.
     * MUST eventually emit a 'close' event for the transport itself.
     * Individual client disconnects should emit 'client_disconnected' events.
     */
    public function close(): void;
}
