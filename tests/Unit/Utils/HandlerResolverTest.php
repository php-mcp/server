<?php

namespace PhpMcp\Server\Tests\Unit\Utils;

use PhpMcp\Server\Utils\HandlerResolver;
use ReflectionMethod;
use ReflectionFunction;
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

// Test closure support
it('resolves closures to ReflectionFunction', function () {
    $closure = function (string $input): string {
        return "processed: $input";
    };

    $resolved = HandlerResolver::resolve($closure);

    expect($resolved)->toBeInstanceOf(ReflectionFunction::class);
    expect($resolved->getNumberOfParameters())->toBe(1);
    expect($resolved->getReturnType()->getName())->toBe('string');
});

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

it('resolves static methods for manual registration', function () {
    $handler = [ValidHandlerClass::class, 'staticMethod'];
    $resolved = HandlerResolver::resolve($handler);

    expect($resolved)->toBeInstanceOf(ReflectionMethod::class);
    expect($resolved->getName())->toBe('staticMethod');
    expect($resolved->isStatic())->toBeTrue();
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
})->throws(InvalidArgumentException::class, 'Invalid handler format. Expected Closure, [ClassName::class, \'methodName\'] or InvokableClassName::class string.');

it('throws for non-invokable class string handler', function () {
    HandlerResolver::resolve(NonInvokableClass::class);
})->throws(InvalidArgumentException::class, "Invokable handler class '" . NonInvokableClass::class . "' must have a public '__invoke' method.");

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

it('throws for abstract method handler', function () {
    HandlerResolver::resolve([AbstractHandlerClass::class, 'abstractMethod']);
})->throws(InvalidArgumentException::class, 'cannot be abstract');

// Test different closure types
it('resolves closures with different signatures', function () {
    $noParams = function () {
        return 'test';
    };
    $withParams = function (int $a, string $b = 'default') {
        return $a . $b;
    };
    $variadic = function (...$args) {
        return $args;
    };

    expect(HandlerResolver::resolve($noParams))->toBeInstanceOf(ReflectionFunction::class);
    expect(HandlerResolver::resolve($withParams))->toBeInstanceOf(ReflectionFunction::class);
    expect(HandlerResolver::resolve($variadic))->toBeInstanceOf(ReflectionFunction::class);

    expect(HandlerResolver::resolve($noParams)->getNumberOfParameters())->toBe(0);
    expect(HandlerResolver::resolve($withParams)->getNumberOfParameters())->toBe(2);
    expect(HandlerResolver::resolve($variadic)->isVariadic())->toBeTrue();
});

// Test that we can distinguish between closures and callable arrays
it('distinguishes between closures and callable arrays', function () {
    $closure = function () {
        return 'closure';
    };
    $array = [ValidHandlerClass::class, 'publicMethod'];
    $string = ValidInvokableClass::class;

    expect(HandlerResolver::resolve($closure))->toBeInstanceOf(ReflectionFunction::class);
    expect(HandlerResolver::resolve($array))->toBeInstanceOf(ReflectionMethod::class);
    expect(HandlerResolver::resolve($string))->toBeInstanceOf(ReflectionMethod::class);
});
