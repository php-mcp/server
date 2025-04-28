<?php

namespace PhpMcp\Server\Defaults;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;

/**
 * A very basic PSR-11 container implementation.
 *
 * Supports getting instances of classes with parameterless constructors.
 */
class BasicContainer implements ContainerInterface
{
    /** @var array<string, object> Simple cache for already created instances */
    private array $instances = [];

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param  string  $id  Identifier of the entry to look for.
     * @return mixed Entry.
     *
     * @throws NotFoundExceptionInterface No entry was found for **this** identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! class_exists($id)) {
            throw new NotFoundException("Class or entry '{$id}' not found.");
        }

        try {
            $reflector = new ReflectionClass($id);
            if (! $reflector->isInstantiable()) {
                throw new ContainerException("Class '{$id}' is not instantiable.");
            }

            $constructor = $reflector->getConstructor();
            if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new ContainerException("Cannot auto-instantiate class '{$id}' with required constructor parameters using this basic container.");
            }

            $instance = $reflector->newInstance();
            $this->instances[$id] = $instance; // Cache the instance

            return $instance;

        } catch (ReflectionException $e) {
            throw new ContainerException("Failed to reflect class '{$id}'.", 0, $e);
        } catch (\Throwable $e) {
            // Catch any other errors during instantiation
            throw new ContainerException("Failed to instantiate class '{$id}'.", 0, $e);
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param  string  $id  Identifier of the entry to look for.
     */
    public function has(string $id): bool
    {
        // Only checks if the class exists, not if it's instantiable by this container
        return class_exists($id);
    }

    /**
     * Adds a pre-built instance to the container (simple singleton behavior).
     * Not part of PSR-11, but useful for basic setup.
     */
    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }
}

// Basic ContainerException (not required by PSR-11 but good practice)
class ContainerException extends \Exception implements \Psr\Container\ContainerExceptionInterface
{
}
