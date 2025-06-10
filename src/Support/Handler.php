<?php

declare(strict_types=1);

namespace PhpMcp\Server\Support;

use InvalidArgumentException;
use PhpMcp\Server\Exception\McpServerException;
use Psr\Container\ContainerInterface;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use TypeError;

class Handler
{
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
    ) {
    }

    public function handle(ContainerInterface $container, array $arguments): mixed
    {
        $instance = $container->get($this->className);
        $arguments = $this->prepareArguments($instance, $arguments);
        $method = $this->methodName;

        return $instance->$method(...$arguments);
    }

    private function prepareArguments(object $instance, array $arguments): array
    {
        if (! method_exists($instance, $this->methodName)) {
            throw new ReflectionException("Method does not exist: {$this->className}::{$this->methodName}");
        }

        $reflectionMethod = new ReflectionMethod($instance, $this->methodName);

        $finalArgs = [];

        foreach ($reflectionMethod->getParameters() as $parameter) {
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
                throw McpServerException::internalError(
                    "Missing required argument `{$paramName}` for {$reflectionMethod->class}::{$this->methodName}."
                );
            }
        }

        return array_values($finalArgs);
    }

    public static function fromArray(array $data): self
    {
        return new self($data['className'], $data['methodName']);
    }

    public function toArray(): array
    {
        return [
            'className' => $this->className,
            'methodName' => $this->methodName,
        ];
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

        if (enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class)) {
            try {
                return $typeName::from($argument);
            } catch (\ValueError $e) {
                $valueStr = is_scalar($argument) ? strval($argument) : gettype($argument);
                throw new InvalidArgumentException(
                    "Invalid value '{$valueStr}' for enum {$typeName}.",
                    0,
                    $e
                );
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
}
