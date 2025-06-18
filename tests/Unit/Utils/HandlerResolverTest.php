<?php

namespace PhpMcp\Server\Tests\Unit\Utils;

use PhpMcp\Server\Utils\HandlerResolver;
use ReflectionMethod;
use InvalidArgumentException;

class ValidHandlerClass
{
    public function publicMethod() {}
    protected function protectedMethod() {}
    private function privateMethod() {}
    public static function staticMethod() {}
    public function __construct() {}
    public function __destruct() {}
}

class ValidInvokableClass
{
    public function __invoke() {}
}

class NonInvokableClass {}

abstract class AbstractHandlerClass
{
    abstract public function abstractMethod();
}

it('resolves valid array handler', function () {
    $handler = [ValidHandlerClass::class, 'publicMethod'];
    $resolved = HandlerResolver::resolve($handler);

    expect($resolved)->toBeInstanceOf(ReflectionMethod::class);
    expect($resolved->getName())->toBe('publicMethod');
    expect($resolved->getDeclaringClass()->getName())->toBe(ValidHandlerClass::class);
});

it('resolves valid invokable class string handler', function () {
    $handler = ValidInvokableClass::class;
    $resolved = HandlerResolver::resolve($handler);

    expect($resolved)->toBeInstanceOf(ReflectionMethod::class);
    expect($resolved->getName())->toBe('__invoke');
    expect($resolved->getDeclaringClass()->getName())->toBe(ValidInvokableClass::class);
});

it('throws for invalid array handler format (count)', function () {
    HandlerResolver::resolve([ValidHandlerClass::class]);
})->throws(InvalidArgumentException::class, 'Invalid array handler format. Expected [ClassName::class, \'methodName\'].');

it('throws for invalid array handler format (types)', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, 123]);
})->throws(InvalidArgumentException::class, 'Invalid array handler format. Expected [ClassName::class, \'methodName\'].');


it('throws for non-existent class in array handler', function () {
    HandlerResolver::resolve(['NonExistentClass', 'method']);
})->throws(InvalidArgumentException::class, "Handler class 'NonExistentClass' not found");

it('throws for non-existent method in array handler', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, 'nonExistentMethod']);
})->throws(InvalidArgumentException::class, "Handler method 'nonExistentMethod' not found in class");

it('throws for non-existent class in string handler', function () {
    HandlerResolver::resolve('NonExistentInvokableClass');
})->throws(InvalidArgumentException::class, 'Invalid handler format. Expected [ClassName::class, \'methodName\'] or InvokableClassName::class string.');


it('throws for non-invokable class string handler', function () {
    HandlerResolver::resolve(NonInvokableClass::class);
})->throws(InvalidArgumentException::class, "Invokable handler class '" . NonInvokableClass::class . "' must have a public '__invoke' method.");

it('throws for static method handler', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, 'staticMethod']);
})->throws(InvalidArgumentException::class, 'cannot be static');

it('throws for protected method handler', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, 'protectedMethod']);
})->throws(InvalidArgumentException::class, 'must be public');

it('throws for private method handler', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, 'privateMethod']);
})->throws(InvalidArgumentException::class, 'must be public');

it('throws for constructor as handler', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, '__construct']);
})->throws(InvalidArgumentException::class, 'cannot be a constructor or destructor');

it('throws for destructor as handler', function () {
    HandlerResolver::resolve([ValidHandlerClass::class, '__destruct']);
})->throws(InvalidArgumentException::class, 'cannot be a constructor or destructor');
