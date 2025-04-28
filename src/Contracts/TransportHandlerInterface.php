<?php

namespace PhpMcp\Server\Contracts;

use PhpMcp\Server\JsonRpc\Response;
use Throwable;

interface TransportHandlerInterface
{
    /**
     * Start the transport handler (e.g., start event loop for STDIO).
     *
     * @return int Exit code (0 for success)
     */
    public function start(): int;

    /**
     * Handle raw input data from the transport.
     *
     * @param  string  $input  Raw data (string or decoded array for HTTP)
     * @param  string  $clientId  Unique client identifier
     * @return bool True if processing was initiated successfully
     */
    public function handleInput(string $input, string $clientId): void;

    /**
     * Send data back through the transport.
     *
     * @param  string  $data  The data to send
     */
    public function sendResponse(string $data, string $clientId): void;

    /**
     * Handle an error that occurred in the transport.
     *
     * @param  Throwable  $error  The error that occurred
     * @return Response|null Error response or null if error was handled
     */
    public function handleError(Throwable $error, string|int|null $id = null): ?Response;

    /**
     * Stop the transport handler.
     */
    public function stop(): void;
}
