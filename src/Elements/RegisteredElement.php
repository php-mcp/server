<?php

declare(strict_types=1);

namespace PhpMcp\Server\Elements;

use InvalidArgumentException;
use JsonSerializable;
use PhpMcp\Server\Exception\McpServerException;
use Psr\Container\ContainerInterface;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use TypeError;

class RegisteredElement implements JsonSerializable
{
    /** @var callable|array|string */
    public readonly mixed $handler;
    public readonly bool $isManual;

    public function __construct(
        callable|array|string $handler,
        bool $isManual = false,
    ) {
        $this->handler = $handler;
        $this->isManual = $isManual;
    }

    public function handle(ContainerInterface $container, array $arguments): mixed
    {
        if (is_string($this->handler)) {
            if (class_exists($this->handler) && method_exists($this->handler, '__invoke')) {
                $reflection = new \ReflectionMethod($this->handler, '__invoke');
                $arguments = $this->prepareArguments($reflection, $arguments);
                $instance = $container->get($this->handler);
                return call_user_func($instance, ...$arguments);
            }

            if (function_exists($this->handler)) {
                $reflection = new \ReflectionFunction($this->handler);
                $arguments = $this->prepareArguments($reflection, $arguments);
                return call_user_func($this->handler, ...$arguments);
            }
        }

        if (is_callable($this->handler)) {
            $reflection = $this->getReflectionForCallable($this->handler);
            $arguments = $this->prepareArguments($reflection, $arguments);
            return call_user_func($this->handler, ...$arguments);
        }

        if (is_array($this->handler)) {
            [$className, $methodName] = $this->handler;
            $reflection = new \ReflectionMethod($className, $methodName);
            $arguments = $this->prepareArguments($reflection, $arguments);

            $instance = $container->get($className);
            return call_user_func([$instance, $methodName], ...$arguments);
        }

        throw new \InvalidArgumentException('Invalid handler type');
    }


    protected function prepareArguments(\ReflectionFunctionAbstract $reflection, array $arguments): array
    {
        $finalArgs = [];

        foreach ($reflection->getParameters() as $parameter) {
            // TODO: Handle variadic parameters.
            $paramName = $parameter->getName();
            $paramPosition = $parameter->getPosition();

            if (isset($arguments[$paramName])) {
                $argument = $arguments[$paramName];
                try {
                    $finalArgs[$paramPosition] = $this->castArgumentType($argument, $parameter);
                } catch (InvalidArgumentException $e) {
                    throw McpServerException::invalidParams($e->getMessage(), $e);
                } catch (Throwable $e) {
                    throw McpServerException::internalError(
                        "Error processing parameter `{$paramName}`: {$e->getMessage()}",
                        $e
                    );
                }
            } elseif ($parameter->isDefaultValueAvailable()) {
                $finalArgs[$paramPosition] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $finalArgs[$paramPosition] = null;
            } elseif ($parameter->isOptional()) {
                continue;
            } else {
                $reflectionName = $reflection instanceof \ReflectionMethod
                    ? $reflection->class . '::' . $reflection->name
                    : 'Closure';
                throw McpServerException::internalError(
                    "Missing required argument `{$paramName}` for {$reflectionName}."
                );
            }
        }

        return array_values($finalArgs);
    }

    /**
     * Gets a ReflectionMethod or ReflectionFunction for a callable.
     */
    private function getReflectionForCallable(callable $handler): \ReflectionMethod|\ReflectionFunction
    {
        if (is_string($handler)) {
            return new \ReflectionFunction($handler);
        }

        if ($handler instanceof \Closure) {
            return new \ReflectionFunction($handler);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            return new \ReflectionMethod($class, $method);
        }

        throw new \InvalidArgumentException('Cannot create reflection for this callable type');
    }

    /**
     * Attempts type casting based on ReflectionParameter type hints.
     *
     * @throws InvalidArgumentException If casting is impossible for the required type.
     * @throws TypeError If internal PHP casting fails unexpectedly.
     */
    private function castArgumentType(mixed $argument, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($argument === null) {
            if ($type && $type->allowsNull()) {
                return null;
            }
        }

        if (! $type instanceof ReflectionNamedType) {
            return $argument;
        }

        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            if (is_object($argument) && $argument instanceof $typeName) {
                return $argument;
            }

            if (is_subclass_of($typeName, \BackedEnum::class)) {
                $value = $typeName::tryFrom($argument);
                if ($value === null) {
                    throw new InvalidArgumentException(
                        "Invalid value '{$argument}' for backed enum {$typeName}. Expected one of its backing values.",
                    );
                }
                return $value;
            } else {
                if (is_string($argument)) {
                    foreach ($typeName::cases() as $case) {
                        if ($case->name === $argument) {
                            return $case;
                        }
                    }
                    $validNames = array_map(fn($c) => $c->name, $typeName::cases());
                    throw new InvalidArgumentException(
                        "Invalid value '{$argument}' for unit enum {$typeName}. Expected one of: " . implode(', ', $validNames) . "."
                    );
                } else {
                    throw new InvalidArgumentException(
                        "Invalid value type '{$argument}' for unit enum {$typeName}. Expected a string matching a case name."
                    );
                }
            }
        }

        try {
            return match (strtolower($typeName)) {
                'int', 'integer' => $this->castToInt($argument),
                'string' => (string) $argument,
                'bool', 'boolean' => $this->castToBoolean($argument),
                'float', 'double' => $this->castToFloat($argument),
                'array' => $this->castToArray($argument),
                default => $argument,
            };
        } catch (TypeError $e) {
            throw new InvalidArgumentException(
                "Value cannot be cast to required type `{$typeName}`.",
                0,
                $e
            );
        }
    }

    /** Helper to cast strictly to boolean */
    private function castToBoolean(mixed $argument): bool
    {
        if (is_bool($argument)) {
            return $argument;
        }
        if ($argument === 1 || $argument === '1' || strtolower((string) $argument) === 'true') {
            return true;
        }
        if ($argument === 0 || $argument === '0' || strtolower((string) $argument) === 'false') {
            return false;
        }
        throw new InvalidArgumentException('Cannot cast value to boolean. Use true/false/1/0.');
    }

    /** Helper to cast strictly to integer */
    private function castToInt(mixed $argument): int
    {
        if (is_int($argument)) {
            return $argument;
        }
        if (is_numeric($argument) && floor((float) $argument) == $argument && ! is_string($argument)) {
            return (int) $argument;
        }
        if (is_string($argument) && ctype_digit(ltrim($argument, '-'))) {
            return (int) $argument;
        }
        throw new InvalidArgumentException('Cannot cast value to integer. Expected integer representation.');
    }

    /** Helper to cast strictly to float */
    private function castToFloat(mixed $argument): float
    {
        if (is_float($argument)) {
            return $argument;
        }
        if (is_int($argument)) {
            return (float) $argument;
        }
        if (is_numeric($argument)) {
            return (float) $argument;
        }
        throw new InvalidArgumentException('Cannot cast value to float. Expected numeric representation.');
    }

    /** Helper to cast strictly to array */
    private function castToArray(mixed $argument): array
    {
        if (is_array($argument)) {
            return $argument;
        }
        throw new InvalidArgumentException('Cannot cast value to array. Expected array.');
    }

    public function toArray(): array
    {
        return [
            'handler' => $this->handler,
            'isManual' => $this->isManual,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
