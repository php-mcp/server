<?php

namespace PhpMcp\Server\Tests\Unit\Transports;

use Mockery;
use Mockery\MockInterface;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

use function React\Async\await;

// --- Setup ---
beforeEach(function () {
    $this->loop = Loop::get();
    /** @var LoggerInterface|MockInterface */
    $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

    $this->transport = new StdioServerTransport();
    $this->transport->setLogger($this->logger);
    $this->transport->setLoop($this->loop);

    $this->inputStreamResource = fopen('php://memory', 'r+');
    $this->outputStreamResource = fopen('php://memory', 'r+');

    $this->transport = new StdioServerTransport($this->inputStreamResource, $this->outputStreamResource);
    $this->transport->setLogger($this->logger);
    $this->transport->setLoop($this->loop);
});

// --- Teardown ---
afterEach(function () {
    if (is_resource($this->inputStreamResource)) {
        fclose($this->inputStreamResource);
    }
    if (is_resource($this->outputStreamResource)) {
        fclose($this->outputStreamResource);
    }

    $reflector = new \ReflectionClass($this->transport);
    $closingProp = $reflector->getProperty('closing');
    $closingProp->setAccessible(true);
    if (! $closingProp->getValue($this->transport)) {
        $this->transport->close();
    }
    Mockery::close();
});

// --- Tests ---

test('implements correct interfaces', function () {
    expect($this->transport)
        ->toBeInstanceOf(ServerTransportInterface::class)
        ->toBeInstanceOf(LoggerAwareInterface::class)
        ->toBeInstanceOf(LoopAwareInterface::class);
});

test('listen() attaches listeners and emits ready/connected', function () {
    $readyEmitted = false;
    $connectedClientId = null;

    $this->transport->on('ready', function () use (&$readyEmitted) {
        $readyEmitted = true;
    });
    $this->transport->on('client_connected', function ($clientId) use (&$connectedClientId) {
        $connectedClientId = $clientId;
    });

    // Act
    $this->transport->listen();

    // Assert internal state
    $reflector = new \ReflectionClass($this->transport);
    $listeningProp = $reflector->getProperty('listening');
    $listeningProp->setAccessible(true);
    expect($listeningProp->getValue($this->transport))->toBeTrue();
    $stdinProp = $reflector->getProperty('stdin');
    $stdinProp->setAccessible(true);
    expect($stdinProp->getValue($this->transport))->toBeInstanceOf(\React\Stream\ReadableResourceStream::class);
    $stdoutProp = $reflector->getProperty('stdout');
    $stdoutProp->setAccessible(true);
    expect($stdoutProp->getValue($this->transport))->toBeInstanceOf(\React\Stream\WritableResourceStream::class);

    // Assert events were emitted (these are synchronous in listen setup)
    expect($readyEmitted)->toBeTrue();
    expect($connectedClientId)->toBe('stdio');

    // Clean up the streams created by listen() if they haven't been closed by other means
    $this->transport->close();
});

test('listen() throws exception if already listening', function () {
    $this->transport->listen();
    $this->transport->listen();
})->throws(TransportException::class, 'Stdio transport is already listening.');

test('receiving data emits message event per line', function () {
    $emittedMessages = [];
    $this->transport->on('message', function ($message, $clientId) use (&$emittedMessages) {
        $emittedMessages[] = ['message' => $message, 'clientId' => $clientId];
    });

    $this->transport->listen();

    $reflector = new \ReflectionClass($this->transport);
    $stdinStreamProp = $reflector->getProperty('stdin');
    $stdinStreamProp->setAccessible(true);
    $stdinStream = $stdinStreamProp->getValue($this->transport);

    // Act
    $line1 = '{"jsonrpc":"2.0", "id":1, "method":"ping"}';
    $line2 = '{"jsonrpc":"2.0", "method":"notify"}';
    $stdinStream->emit('data', [$line1."\n".$line2."\n"]);

    // Assert
    expect($emittedMessages)->toHaveCount(2);
    expect($emittedMessages[0]['message'])->toBe($line1);
    expect($emittedMessages[0]['clientId'])->toBe('stdio');
    expect($emittedMessages[1]['message'])->toBe($line2);
    expect($emittedMessages[1]['clientId'])->toBe('stdio');
});

test('receiving partial data does not emit message', function () {
    $messageEmitted = false;
    $this->transport->on('message', function () use (&$messageEmitted) {
        $messageEmitted = true;
    });

    $this->transport->listen();

    $reflector = new \ReflectionClass($this->transport);
    $stdinStreamProp = $reflector->getProperty('stdin');
    $stdinStreamProp->setAccessible(true);
    $stdinStream = $stdinStreamProp->getValue($this->transport);

    $stdinStream->emit('data', ['{"jsonrpc":"2.0", "id":1']);

    expect($messageEmitted)->toBeFalse();
})->group('usesLoop');

test('receiving buffered data emits messages correctly', function () {
    $emittedMessages = [];
    $this->transport->on('message', function ($message, $clientId) use (&$emittedMessages) {
        $emittedMessages[] = ['message' => $message, 'clientId' => $clientId];
    });

    $this->transport->listen();

    $reflector = new \ReflectionClass($this->transport);
    $stdinStreamProp = $reflector->getProperty('stdin');
    $stdinStreamProp->setAccessible(true);
    $stdinStream = $stdinStreamProp->getValue($this->transport);

    // Write part 1
    $stdinStream->emit('data', ["{\"id\":1}\n{\"id\":2"]);
    expect($emittedMessages)->toHaveCount(1);
    expect($emittedMessages[0]['message'])->toBe('{"id":1}');

    // Write part 2
    $stdinStream->emit('data', ["}\n{\"id\":3}\n"]);
    expect($emittedMessages)->toHaveCount(3);
    expect($emittedMessages[1]['message'])->toBe('{"id":2}');
    expect($emittedMessages[2]['message'])->toBe('{"id":3}');

})->group('usesLoop');

test('sendToClientAsync() rejects if closed', function () {
    $this->transport->listen();
    $this->transport->close(); // Close it first

    $promise = $this->transport->sendToClientAsync('stdio', "{}\n");
    await($promise);

})->throws(TransportException::class, 'Stdio transport is closed');

test('sendToClientAsync() rejects for invalid client ID', function () {
    $this->transport->listen();
    $promise = $this->transport->sendToClientAsync('invalid_client', "{}\n");
    await($promise);

})->throws(TransportException::class, 'Invalid clientId');

test('close() closes streams and emits close event', function () {
    $this->transport->listen(); // Setup streams internally

    $closeEmitted = false;
    $this->transport->on('close', function () use (&$closeEmitted) {
        $closeEmitted = true;
    });

    // Get stream instances after listen()
    $reflector = new \ReflectionClass($this->transport);
    $stdinStream = $reflector->getProperty('stdin')->getValue($this->transport);
    $stdoutStream = $reflector->getProperty('stdout')->getValue($this->transport);

    $stdinClosed = false;
    $stdoutClosed = false;
    $stdinStream->on('close', function () use (&$stdinClosed) {
        $stdinClosed = true;
    });
    $stdoutStream->on('close', function () use (&$stdoutClosed) {
        $stdoutClosed = true;
    });

    // Act
    $this->transport->close();

    // Assert internal state
    expect($reflector->getProperty('stdin')->getValue($this->transport))->toBeNull();
    expect($reflector->getProperty('stdout')->getValue($this->transport))->toBeNull();
    expect($reflector->getProperty('closing')->getValue($this->transport))->toBeTrue();
    expect($reflector->getProperty('listening')->getValue($this->transport))->toBeFalse();

    // Assert event emission
    expect($closeEmitted)->toBeTrue();

    // Assert streams were closed (via events)
    expect($stdinClosed)->toBeTrue();
    expect($stdoutClosed)->toBeTrue();
});

test('stdin close event emits client_disconnected and closes transport', function () {
    $disconnectedClientId = null;
    $closeEmitted = false;

    $this->transport->on('client_disconnected', function ($clientId) use (&$disconnectedClientId) {
        $disconnectedClientId = $clientId;
    });

    $this->transport->on('close', function () use (&$closeEmitted) {
        $closeEmitted = true;
    });

    $this->transport->listen();

    $reflector = new \ReflectionClass($this->transport);
    $stdinStream = $reflector->getProperty('stdin')->getValue($this->transport);

    $stdinStream->close();

    $this->loop->addTimer(0.01, fn () => $this->loop->stop());
    $this->loop->run();

    // Assert
    expect($disconnectedClientId)->toBe('stdio');
    expect($closeEmitted)->toBeTrue();

    expect($reflector->getProperty('closing')->getValue($this->transport))->toBeTrue();

})->group('usesLoop');
