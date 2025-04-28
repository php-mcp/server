<?php

namespace PhpMcp\Server\Tests\TestDoubles;

/**
 * Dummy class for testing tool calls in the Processor
 */
class DummyToolClass
{
    /**
     * A simple method that can be mocked for testing
     */
    public function methodA(string $param1, int $param2 = 0): array
    {
        // In real testing this will be mocked, but provide a default implementation
        return [
            'param1' => $param1,
            'param2' => $param2,
            'status' => 'success',
        ];
    }

    /**
     * Another method for testing different tools
     */
    public function methodB(): string
    {
        return 'Method B result';
    }

    /**
     * Generic execution method used in many tests
     */
    public function execute(string $param1 = '', int $param2 = 0): array
    {
        return [
            'executed' => true,
            'params' => [$param1, $param2],
        ];
    }
}
