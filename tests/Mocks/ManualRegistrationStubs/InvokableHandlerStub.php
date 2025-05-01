<?php

namespace PhpMcp\Server\Tests\Mocks\ManualRegistrationStubs;

/**
 * An invokable class for testing manual registration.
 */
class InvokableHandlerStub
{
    public function __invoke(string $data): string
    {
        return 'Invoked handler with: '.$data;
    }
}
