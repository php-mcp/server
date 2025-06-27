<?php

declare(strict_types=1);

namespace PhpMcp\Server\Utils;

use InvalidArgumentException;
use ReflectionMethod;
use ReflectionException;

/**
 * Utility class to validate and resolve MCP element handlers.
 */
class HandlerResolver
{
    /**
     * Validates and resolves a handler to a ReflectionMethod or ReflectionFunction instance.
     *
     * A handler can be:
     * - A Closure: function() { ... }
     * - An array: [ClassName::class, 'methodName'] (instance method)
     * - An array: [ClassName::class, 'staticMethod'] (static method, if callable)
     * - A string: InvokableClassName::class (which will resolve to its '__invoke' method)
     *
     * @param \Closure|array|string $handler The handler to resolve.
     * @return \ReflectionMethod|\ReflectionFunction
     *
     * @throws InvalidArgumentException If the handler format is invalid, the class/method doesn't exist,
     *                                  or the method is unsuitable (e.g., private, abstract).
     */
    public static function resolve(\Closure|array|string $handler): \ReflectionMethod|\ReflectionFunction
    {
        // Handle Closures
        if ($handler instanceof \Closure) {
            return new \ReflectionFunction($handler);
        }

        $className = null;
        $methodName = null;

        if (is_array($handler)) {
            if (count($handler) !== 2 || !isset($handler[0]) || !isset($handler[1]) || !is_string($handler[0]) || !is_string($handler[1])) {
                throw new InvalidArgumentException('Invalid array handler format. Expected [ClassName::class, \'methodName\'].');
            }
            [$className, $methodName] = $handler;
            if (!class_exists($className)) {
                throw new InvalidArgumentException("Handler class '{$className}' not found for array handler.");
            }
            if (!method_exists($className, $methodName)) {
                throw new InvalidArgumentException("Handler method '{$methodName}' not found in class '{$className}' for array handler.");
            }
        } elseif (is_string($handler) && class_exists($handler)) {
            $className = $handler;
            $methodName = '__invoke';
            if (!method_exists($className, $methodName)) {
                throw new InvalidArgumentException("Invokable handler class '{$className}' must have a public '__invoke' method.");
            }
        } else {
            throw new InvalidArgumentException('Invalid handler format. Expected Closure, [ClassName::class, \'methodName\'] or InvokableClassName::class string.');
        }

        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);

            // For discovered elements (non-manual), still reject static methods
            // For manual elements, we'll allow static methods since they're callable
            if (!$reflectionMethod->isPublic()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' must be public.");
            }
            if ($reflectionMethod->isAbstract()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be abstract.");
            }
            if ($reflectionMethod->isConstructor() || $reflectionMethod->isDestructor()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be a constructor or destructor.");
            }

            return $reflectionMethod;
        } catch (ReflectionException $e) {
            // This typically occurs if class_exists passed but ReflectionMethod still fails (rare)
            throw new InvalidArgumentException("Reflection error for handler '{$className}::{$methodName}': {$e->getMessage()}", 0, $e);
        }
    }
}
