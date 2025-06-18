<?php

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

function getPrivateProperty(object $object, string $propertyName)
{
    $reflector = new ReflectionClass($object);
    $property = $reflector->getProperty($propertyName);
    $property->setAccessible(true);
    return $property->getValue($object);
}

function delay($time, ?LoopInterface $loop = null)
{
    if ($loop === null) {
        $loop = Loop::get();
    }

    /** @var TimerInterface $timer */
    $timer = null;
    return new Promise(function ($resolve) use ($loop, $time, &$timer) {
        $timer = $loop->addTimer($time, function () use ($resolve) {
            $resolve(null);
        });
    }, function () use (&$timer, $loop) {
        $loop->cancelTimer($timer);
        $timer = null;

        throw new \RuntimeException('Timer cancelled');
    });
}

function timeout(PromiseInterface $promise, $time, ?LoopInterface $loop = null)
{
    $canceller = null;
    if (\method_exists($promise, 'cancel')) {
        $canceller = function () use (&$promise) {
            $promise->cancel();
            $promise = null;
        };
    }

    if ($loop === null) {
        $loop = Loop::get();
    }

    return new Promise(function ($resolve, $reject) use ($loop, $time, $promise) {
        $timer = null;
        $promise = $promise->then(function ($v) use (&$timer, $loop, $resolve) {
            if ($timer) {
                $loop->cancelTimer($timer);
            }
            $timer = false;
            $resolve($v);
        }, function ($v) use (&$timer, $loop, $reject) {
            if ($timer) {
                $loop->cancelTimer($timer);
            }
            $timer = false;
            $reject($v);
        });

        if ($timer === false) {
            return;
        }

        // start timeout timer which will cancel the input promise
        $timer = $loop->addTimer($time, function () use ($time, &$promise, $reject) {
            $reject(new \RuntimeException('Timed out after ' . $time . ' seconds'));

            if (\method_exists($promise, 'cancel')) {
                $promise->cancel();
            }
            $promise = null;
        });
    }, $canceller);
}

function findFreePort()
{
    $server = new SocketServer('127.0.0.1:0');
    $address = $server->getAddress();
    $port = $address ? parse_url($address, PHP_URL_PORT) : null;
    $server->close();
    if (!$port) {
        throw new \RuntimeException("Could not find a free port for testing.");
    }
    return (int)$port;
}
