<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Interface for server-side MCP transports.
 *
 * Implementations handle listening for connections/data and sending raw messages.
 * MUST emit events for lifecycle and messages.
 *
 * --- Expected Emitted Events ---
 * 'ready': () - Optional: Fired when listening starts successfully.
 * 'client_connected': (string $clientId) - New client connection (e.g., SSE).
 * 'message': (string $rawJsonRpcFrame, string $clientId) - Complete message received from a client.
 * 'client_disconnected': (string $clientId, ?string $reason) - Client connection closed.
 * 'error': (Throwable $error, ?string $clientId) - Error occurred (general transport error if clientId is null).
 * 'close': (?string $reason) - Transport listener stopped completely.
 */
interface ServerTransportInterface extends EventEmitterInterface
{
    /**
     * Starts the transport listener (e.g., listens on STDIN, starts HTTP server).
     * Does NOT run the event loop itself. Prepares transport to emit events when loop runs.
     *
     * @throws \PhpMcp\Server\Exception\TransportException on immediate setup failure (e.g., port binding).
     */
    public function listen(): void;

    /**
     * Sends a raw, framed message to a specific connected client.
     * The message MUST be a complete JSON-RPC frame (typically ending in "\n" for line-based transports
     * or formatted as an SSE event for HTTP transports). Framing is the responsibility of the caller
     * (typically the ProtocolHandler) as it depends on the transport type.
     *
     * @param  string  $clientId  Target client identifier ("stdio" is conventionally used for stdio transport).
     * @param  string  $rawFramedMessage  Message string ready for transport.
     * @return PromiseInterface<void> Resolves on successful send/queue, rejects on specific send error.
     */
    public function sendToClientAsync(string $clientId, string $rawFramedMessage): PromiseInterface;

    /**
     * Stops the transport listener gracefully and closes all active connections.
     * MUST eventually emit a 'close' event for the transport itself.
     * Individual client disconnects should emit 'client_disconnected' events.
     */
    public function close(): void;
}
