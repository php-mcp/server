<?php

namespace PhpMcp\Server\Defaults;

use ArrayAccess;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;

class ArrayConfigurationRepository implements ArrayAccess, ConfigurationRepositoryInterface
{
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null; // Simplistic check, might need refinement for explicit nulls
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        if (strpos($key, '.') === false) {
            return $default;
        }

        $items = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (is_array($items) && array_key_exists($segment, $items)) {
                $items = $items[$segment];
            } else {
                return $default;
            }
        }

        return $items;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $items = &$this->items;

        while (count($keys) > 1) {
            $key = array_shift($keys);
            if (! isset($items[$key]) || ! is_array($items[$key])) {
                $items[$key] = [];
            }
            $items = &$items[$key];
        }

        $items[array_shift($keys)] = $value;
    }

    public function offsetExists(mixed $key): bool
    {
        return $this->has($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        return $this->get($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function offsetUnset(mixed $key): void
    {
        $this->set($key, null); // Or implement actual unset logic if needed
    }

    public function all(): array
    {
        return $this->items;
    }
}
