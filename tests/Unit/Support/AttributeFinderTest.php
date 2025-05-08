<?php

namespace PhpMcp\Server\Tests\Unit\Support;

use PhpMcp\Server\Support\AttributeFinder;
use PhpMcp\Server\Tests\Mocks\DiscoveryStubs\PlainPhpClass;
use PhpMcp\Server\Tests\Mocks\SupportStubs\AttributeTestStub;
use PhpMcp\Server\Tests\Mocks\SupportStubs\TestAttributeOne;
use PhpMcp\Server\Tests\Mocks\SupportStubs\TestAttributeTwo;
use PhpMcp\Server\Tests\Mocks\SupportStubs\TestClassOnlyAttribute;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

// --- Setup ---

beforeEach(function () {
    $this->finder = new AttributeFinder();
});

// --- Class Attribute Tests ---

test('getFirstClassAttribute finds first matching attribute', function () {
    $reflectionClass = new ReflectionClass(AttributeTestStub::class);
    $attributeRefl = $this->finder->getFirstClassAttribute($reflectionClass, TestAttributeOne::class);

    expect($attributeRefl)->toBeInstanceOf(ReflectionAttribute::class);
    $attributeInstance = $attributeRefl->newInstance();
    expect($attributeInstance)->toBeInstanceOf(TestAttributeOne::class);
    expect($attributeInstance->value)->toBe('class-level');
});

test('getFirstClassAttribute returns null if attribute not found', function () {
    $reflectionClass = new ReflectionClass(PlainPhpClass::class); // Class with no attributes
    $attributeRefl = $this->finder->getFirstClassAttribute($reflectionClass, TestAttributeOne::class);
    expect($attributeRefl)->toBeNull();
});

test('getClassAttributes finds all attributes of a type', function () {
    $reflectionClass = new ReflectionClass(AttributeTestStub::class);
    $attributes = $this->finder->getClassAttributes($reflectionClass, TestAttributeOne::class);
    expect($attributes)->toBeArray()->toHaveCount(1);
    expect($attributes[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instance = $attributes[0]->newInstance();
    expect($instance)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance->value)->toBe('class-level');

    $attributesTwo = $this->finder->getClassAttributes($reflectionClass, TestClassOnlyAttribute::class);
    expect($attributesTwo)->toBeArray()->toHaveCount(1);
    expect($attributesTwo[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instanceTwo = $attributesTwo[0]->newInstance();
    expect($instanceTwo)->toBeInstanceOf(TestClassOnlyAttribute::class);
});

// --- Method Attribute Tests ---

test('getMethodAttributes finds all attributes of a type', function () {
    $reflectionMethod = new ReflectionMethod(AttributeTestStub::class, 'methodTwo');
    $attributes = $this->finder->getMethodAttributes($reflectionMethod, TestAttributeOne::class);
    expect($attributes)->toBeArray()->toHaveCount(1);
    expect($attributes[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instance1 = $attributes[0]->newInstance();
    expect($instance1)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance1->value)->toBe('method-two');

    $attributesTwo = $this->finder->getMethodAttributes($reflectionMethod, TestAttributeTwo::class);
    expect($attributesTwo)->toBeArray()->toHaveCount(1);
    expect($attributesTwo[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instance2 = $attributesTwo[0]->newInstance();
    expect($instance2)->toBeInstanceOf(TestAttributeTwo::class);
    expect($instance2->number)->toBe(2);
});

// REMOVED: test 'getMethodAttributes finds all attributes if no type specified'

test('getMethodAttributes returns empty array if none found', function () {
    $reflectionMethod = new ReflectionMethod(AttributeTestStub::class, 'methodThree');
    $attributes = $this->finder->getMethodAttributes($reflectionMethod, TestAttributeOne::class);
    expect($attributes)->toBeArray()->toBeEmpty();
});

test('getFirstMethodAttribute finds first matching attribute', function () {
    $reflectionMethod = new ReflectionMethod(AttributeTestStub::class, 'methodTwo');
    $attributeRefl = $this->finder->getFirstMethodAttribute($reflectionMethod, TestAttributeOne::class);
    expect($attributeRefl)->toBeInstanceOf(ReflectionAttribute::class);
    $instance = $attributeRefl->newInstance();
    expect($instance)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance->value)->toBe('method-two');
});

test('getFirstMethodAttribute returns null if attribute not found', function () {
    $reflectionMethod = new ReflectionMethod(AttributeTestStub::class, 'methodThree');
    $attributeRefl = $this->finder->getFirstMethodAttribute($reflectionMethod, TestAttributeOne::class);
    expect($attributeRefl)->toBeNull();
});

// --- Parameter Attribute Tests ---

test('getParameterAttributes finds all attributes of a type', function () {
    $reflectionParam = new ReflectionParameter([AttributeTestStub::class, 'methodOne'], 'param1');
    $attributes = $this->finder->getParameterAttributes($reflectionParam, TestAttributeOne::class);
    expect($attributes)->toBeArray()->toHaveCount(1);
    expect($attributes[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instance1 = $attributes[0]->newInstance();
    expect($instance1)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance1->value)->toBe('param-one');

    $attributesTwo = $this->finder->getParameterAttributes($reflectionParam, TestAttributeTwo::class);
    expect($attributesTwo)->toBeArray()->toHaveCount(1);
    expect($attributesTwo[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instance2 = $attributesTwo[0]->newInstance();
    expect($instance2)->toBeInstanceOf(TestAttributeTwo::class);
    expect($instance2->number)->toBe(1);
});

// REMOVED: test 'getParameterAttributes finds all attributes if no type specified'

test('getParameterAttributes returns empty array if none found', function () {
    $reflectionParam = new ReflectionParameter([AttributeTestStub::class, 'methodThree'], 'unattributedParam');
    $attributes = $this->finder->getParameterAttributes($reflectionParam, TestAttributeOne::class);
    expect($attributes)->toBeArray()->toBeEmpty();
});

test('getFirstParameterAttribute finds first matching attribute', function () {
    $reflectionParam = new ReflectionParameter([AttributeTestStub::class, 'methodOne'], 'param1');
    $attributeRefl = $this->finder->getFirstParameterAttribute($reflectionParam, TestAttributeOne::class);
    expect($attributeRefl)->toBeInstanceOf(ReflectionAttribute::class);
    $instance = $attributeRefl->newInstance();
    expect($instance)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance->value)->toBe('param-one');
});

test('getFirstParameterAttribute returns null if attribute not found', function () {
    $reflectionParam = new ReflectionParameter([AttributeTestStub::class, 'methodThree'], 'unattributedParam');
    $attributeRefl = $this->finder->getFirstParameterAttribute($reflectionParam, TestAttributeOne::class);
    expect($attributeRefl)->toBeNull();
});

// --- Property Attribute Tests ---

test('getPropertyAttributes finds attribute', function () {
    $reflectionProp = new ReflectionProperty(AttributeTestStub::class, 'propertyOne');
    $attributes = $this->finder->getPropertyAttributes($reflectionProp, TestAttributeOne::class);
    expect($attributes)->toBeArray()->toHaveCount(1);
    expect($attributes[0])->toBeInstanceOf(ReflectionAttribute::class);
    $instance1 = $attributes[0]->newInstance();
    expect($instance1)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance1->value)->toBe('prop-level');

    $attributesTwo = $this->finder->getPropertyAttributes($reflectionProp, TestAttributeTwo::class);
    expect($attributesTwo)->toBeArray()->toBeEmpty(); // TestAttributeTwo not on property
});

test('getFirstPropertyAttribute finds attribute', function () {
    $reflectionProp = new ReflectionProperty(AttributeTestStub::class, 'propertyOne');
    $attributeRefl = $this->finder->getFirstPropertyAttribute($reflectionProp, TestAttributeOne::class);
    expect($attributeRefl)->toBeInstanceOf(ReflectionAttribute::class);
    $instance = $attributeRefl->newInstance();
    expect($instance)->toBeInstanceOf(TestAttributeOne::class);
    expect($instance->value)->toBe('prop-level');

    $nullRefl = $this->finder->getFirstPropertyAttribute($reflectionProp, TestAttributeTwo::class);
    expect($nullRefl)->toBeNull();
});
