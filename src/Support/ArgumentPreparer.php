<?php

namespace PhpMcp\Server\Support;

use InvalidArgumentException;
use PhpMcp\Server\Exceptions\McpException;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use TypeError;
use Psr\Log\LoggerInterface;

/**
 * Prepares arguments for PHP method invocation based on validated input and reflection.
 */
class ArgumentPreparer
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Prepares the arguments array in the correct order for method invocation.
     *
     * @param  object  $instance  The class instance where the method resides.
     * @param  string  $methodName  The name of the method to prepare arguments for.
     * @param  array  $validatedInput  Key-value array of validated input arguments.
     * @param  array  $schema  Optional JSON Schema (as array) for the input (currently unused here).
     * @return list<mixed> The ordered list of arguments for splat (...) operator or invokeArgs.
     *
     * @throws McpException If preparation fails (e.g., required arg missing, type casting fails).
     * @throws ReflectionException If method/parameter reflection fails.
     */
    public function prepareMethodArguments(
        object $instance,
        string $methodName,
        array $validatedInput,
        array $schema = []
    ): array {
        if (! method_exists($instance, $methodName)) {
            throw new ReflectionException('Method does not exist: '.get_class($instance)."::{$methodName}");
        }

        $reflectionMethod = new ReflectionMethod($instance, $methodName);
        $finalArgs = [];

        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $paramName = $reflectionParameter->getName();
            $paramPosition = $reflectionParameter->getPosition();

            if (isset($validatedInput[$paramName])) {
                $inputValue = $validatedInput[$paramName];
                try {
                    $finalArgs[$paramPosition] = $this->castArgumentType($inputValue, $reflectionParameter);
                } catch (InvalidArgumentException $e) {
                    throw McpException::invalidParams($e->getMessage(), $e);
                } catch (Throwable $e) {
                    // Catch other unexpected casting errors
                    throw McpException::internalError(
                        "Error processing parameter `{$paramName}`: {$e->getMessage()}",
                        $e
                    );
                }
            } elseif ($reflectionParameter->isDefaultValueAvailable()) {
                $finalArgs[$paramPosition] = $reflectionParameter->getDefaultValue();
            } elseif ($reflectionParameter->allowsNull()) {
                $finalArgs[$paramPosition] = null;
            } elseif ($reflectionParameter->isOptional()) {
                continue;
            } else {
                // If this happens, it's likely a mismatch between schema validation and reflection
                $this->logger->error("Invariant violation: Missing required argument `{$paramName}` for {$reflectionMethod->class}::{$methodName} despite passing schema validation.", [
                    'method' => $methodName,
                    'parameter' => $paramName,
                    'validated_input_keys' => array_keys($validatedInput),
                    'schema' => $schema, // Log schema for debugging
                ]);
                throw McpException::internalError(
                    "Missing required argument `{$paramName}` for {$reflectionMethod->class}::{$methodName}."
                );
            }
        }
        return array_values($finalArgs);
    }

    /**
     * Attempts type casting based on ReflectionParameter type hints.
     *
     * @throws InvalidArgumentException If casting is impossible for the required type.
     * @throws TypeError If internal PHP casting fails unexpectedly.
     */
    private function castArgumentType(mixed $value, ReflectionParameter $rp): mixed
    {
        $type = $rp->getType();

        if ($value === null) {
            if ($type && $type->allowsNull()) {
                return null;
            }
        }

        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // --- Handle Backed Enum ---
        if (enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class)) {
            try {
                return $typeName::from($value);
            } catch (\ValueError $e) {
                // Provide a more specific error message
                $valueStr = is_scalar($value) ? strval($value) : gettype($value);
                throw new InvalidArgumentException(
                    "Invalid value '{$valueStr}' for enum {$typeName}.",
                    0,
                    $e
                );
            }
        }
        // --- End Enum Handling ---

        // --- Handle Scalar Types ---
        try {
            return match (strtolower($typeName)) {
                'int', 'integer' => $this->castToInt($value),
                'string' => (string) $value,
                'bool', 'boolean' => $this->castToBoolean($value),
                'float', 'double' => $this->castToFloat($value),
                'array' => $this->castToArray($value),
                default => $value,
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
    private function castToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || strtolower((string) $value) === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || strtolower((string) $value) === 'false') {
            return false;
        }
        throw new InvalidArgumentException('Cannot cast value to boolean. Use true/false/1/0.');
    }

    /** Helper to cast strictly to integer */
    private function castToInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value) && floor((float) $value) == $value && ! is_string($value)) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }
        throw new InvalidArgumentException('Cannot cast value to integer. Expected integer representation.');
    }

    /** Helper to cast strictly to float */
    private function castToFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        throw new InvalidArgumentException('Cannot cast value to float. Expected numeric representation.');
    }

    /** Helper to cast strictly to array */
    private function castToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Cannot cast value to array. Expected array.');
    }
}
