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
     * Mark the session as initialized.
     */
    public function initialize(): void;

    /**
     * Update the last activity timestamp for the session.
     */
    public function touch(): void;

    /**
     * Check if the session has been initialized.
     */
    public function isInitialized(): bool;

    /**
     * Get a specific attribute from the session.
     * Supports dot notation for nested access.
     */
    public function getAttribute(string $key, mixed $default = null): mixed;

    /**
     * Set a specific attribute in the session.
     * Supports dot notation for nested access.
     */
    public function setAttribute(string $key, mixed $value, bool $overwrite = true): void;

    /**
     * Check if an attribute exists in the session.
     * Supports dot notation for nested access.
     */
    public function hasAttribute(string $key): bool;

    /**
     * Remove an attribute from the session.
     * Supports dot notation for nested access.
     */
    public function forgetAttribute(string $key): void;

    /**
     * Get an attribute's value and then remove it from the session.
     * Supports dot notation for nested access.
     */
    public function pullAttribute(string $key, mixed $default = null): mixed;

    /**
     * Get all attributes of the session.
     */
    public function getAttributes(): array;

    /**
     * Set all attributes of the session, typically for hydration.
     * This will overwrite existing attributes.
     */
    public function setAttributes(array $attributes): void;

    /**
     * Add a raw framed message to the session's outgoing queue.
     */
    public function queueMessage(string $rawFramedMessage): void;

    /**
     * Retrieve and remove all messages from the queue.
     * @return array<string>
     */
    public function dequeueMessages(): array;

    /**
     * Check if there are any messages in the queue.
     */
    public function hasQueuedMessages(): bool;
}
