<?php

namespace PhpMcp\Server\Contracts;

interface ConfigurationRepositoryInterface
{
    /**
     * Get the specified configuration value.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a given configuration value.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Determine if the given configuration value exists.
     */
    public function has(string $key): bool;
}
