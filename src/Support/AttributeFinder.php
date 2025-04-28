<?php

namespace PhpMcp\Server\Support;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * Utility class for finding attributes using PHP Reflection.
 */
class AttributeFinder
{
    /**
     * Get all attributes of a specific type from a class.
     *
     * @template T of object
     *
     * @param  ReflectionClass  $reflectionClass  The reflection class.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return array<int, ReflectionAttribute<T>> An array of ReflectionAttribute instances.
     */
    public function getClassAttributes(ReflectionClass $reflectionClass, string $attributeClass): array
    {
        return $reflectionClass->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);
    }

    /**
     * Get the first attribute of a specific type from a class.
     *
     * @template T of object
     *
     * @param  ReflectionClass  $reflectionClass  The reflection class.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return ReflectionAttribute<T>|null The first matching ReflectionAttribute instance or null.
     */
    public function getFirstClassAttribute(ReflectionClass $reflectionClass, string $attributeClass): ?ReflectionAttribute
    {
        $attributes = $this->getClassAttributes($reflectionClass, $attributeClass);

        return $attributes[0] ?? null;
    }

    /**
     * Get all attributes of a specific type from a method.
     *
     * @template T of object
     *
     * @param  ReflectionMethod  $reflectionMethod  The reflection method.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return array<int, ReflectionAttribute<T>> An array of ReflectionAttribute instances.
     */
    public function getMethodAttributes(ReflectionMethod $reflectionMethod, string $attributeClass): array
    {
        return $reflectionMethod->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);
    }

    /**
     * Get the first attribute of a specific type from a method.
     *
     * @template T of object
     *
     * @param  ReflectionMethod  $reflectionMethod  The reflection method.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return ReflectionAttribute<T>|null The first matching ReflectionAttribute instance or null.
     */
    public function getFirstMethodAttribute(ReflectionMethod $reflectionMethod, string $attributeClass): ?ReflectionAttribute
    {
        $attributes = $this->getMethodAttributes($reflectionMethod, $attributeClass);

        return $attributes[0] ?? null;
    }

    /**
     * Get all attributes of a specific type from a property.
     *
     * @template T of object
     *
     * @param  ReflectionProperty  $reflectionProperty  The reflection property.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return array<int, ReflectionAttribute<T>> An array of ReflectionAttribute instances.
     */
    public function getPropertyAttributes(ReflectionProperty $reflectionProperty, string $attributeClass): array
    {
        return $reflectionProperty->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);
    }

    /**
     * Get the first attribute of a specific type from a property.
     *
     * @template T of object
     *
     * @param  ReflectionProperty  $reflectionProperty  The reflection property.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return ReflectionAttribute<T>|null The first matching ReflectionAttribute instance or null.
     */
    public function getFirstPropertyAttribute(ReflectionProperty $reflectionProperty, string $attributeClass): ?ReflectionAttribute
    {
        $attributes = $this->getPropertyAttributes($reflectionProperty, $attributeClass);

        return $attributes[0] ?? null;
    }

    /**
     * Get all attributes of a specific type from a parameter.
     *
     * @template T of object
     *
     * @param  ReflectionParameter  $reflectionParameter  The reflection parameter.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return array<int, ReflectionAttribute<T>> An array of ReflectionAttribute instances.
     */
    public function getParameterAttributes(ReflectionParameter $reflectionParameter, string $attributeClass): array
    {
        return $reflectionParameter->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);
    }

    /**
     * Get the first attribute of a specific type from a parameter.
     *
     * @template T of object
     *
     * @param  ReflectionParameter  $reflectionParameter  The reflection parameter.
     * @param  class-string<T>  $attributeClass  The class name of the attribute to find.
     * @return ReflectionAttribute<T>|null The first matching ReflectionAttribute instance or null.
     */
    public function getFirstParameterAttribute(ReflectionParameter $reflectionParameter, string $attributeClass): ?ReflectionAttribute
    {
        $attributes = $this->getParameterAttributes($reflectionParameter, $attributeClass);

        return $attributes[0] ?? null;
    }
}
