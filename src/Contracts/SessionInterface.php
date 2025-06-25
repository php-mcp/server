<?php

declare(strict_types=1);

namespace PhpMcp\Server\Contracts;

use JsonSerializable;

interface SessionInterface extends JsonSerializable
{
    /**
     * Get the session ID.
     */
    public function getId(): string;

    /**
     * Save the session.
     */
    public function save(): void;

    /**
     * Get a specific attribute from the session.
     * Supports dot notation for nested access.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a specific attribute in the session.
     * Supports dot notation for nested access.
     */
    public function set(string $key, mixed $value, bool $overwrite = true): void;

    /**
     * Check if an attribute exists in the session.
     * Supports dot notation for nested access.
     */
    public function has(string $key): bool;

    /**
     * Remove an attribute from the session.
     * Supports dot notation for nested access.
     */
    public function forget(string $key): void;

    /**
     * Remove all attributes from the session.
     */
    public function clear(): void;

    /**
     * Get an attribute's value and then remove it from the session.
     * Supports dot notation for nested access.
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Get all attributes of the session.
     */
    public function all(): array;

    /**
     * Set all attributes of the session, typically for hydration.
     * This will overwrite existing attributes.
     */
    public function hydrate(array $attributes): void;

    /**
     * Add a message to the session's queue.
     */
    public function queueMessage(string $message): void;

    /**
     * Retrieve and remove all messages from the queue.
     * @return array<string>
     */
    public function dequeueMessages(): array;

    /**
     * Check if there are any messages in the queue.
     */
    public function hasQueuedMessages(): bool;

    /**
     * Get the session handler instance.
     *
     * @return SessionHandlerInterface
     */
    public function getHandler(): SessionHandlerInterface;
}
