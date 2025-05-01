<?php

namespace PhpMcp\Server\Tests\Mocks\ManualRegistrationStubs;

/**
 * A regular class with methods for testing manual registration.
 */
class HandlerStub
{
    public function toolHandler(string $input): string
    {
        return 'Tool executed with: '.$input;
    }

    public function resourceHandler(): string
    {
        return 'Resource content';
    }

    public function promptHandler(string $topic): array
    {
        return [['role' => 'user', 'content' => "Prompt for {$topic}"]];
    }

    public function templateHandler(string $id): array
    {
        return ['id' => $id, 'content' => 'Template data'];
    }
}
