<?php

namespace PhpMcp\Server\Tests\Mocks;

use PhpMcp\Server\Processor;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Transports\StdioTransportHandler;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * A test version of StdioTransportHandler that allows for easier testing
 */
class StdioTransportHandlerMock extends StdioTransportHandler
{
    /**
     * The client ID used for testing
     */
    public const CLIENT_ID = 'stdio_client';

    /**
     * Create a new test StdioTransportHandler
     */
    public function __construct(
        private readonly Processor $processor,
        private readonly TransportState $transportState,
        private readonly LoggerInterface $logger,
        ?ReadableStreamInterface $inputStream = null,
        ?WritableStreamInterface $outputStream = null,
        ?LoopInterface $loop = null
    ) {
        parent::__construct($processor, $transportState, $logger);

        // If provided, override the streams and loop with our test versions
        if ($inputStream) {
            $this->inputStream = $inputStream;
        }

        if ($outputStream) {
            $this->outputStream = $outputStream;
        }

        if ($loop) {
            $this->loop = $loop;
        }
    }
}
