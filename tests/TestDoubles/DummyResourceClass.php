<?php

namespace PhpMcp\Server\Tests\TestDoubles;

class DummyResourceClass
{
    public function getResource(): string
    {
        return 'Resource content';
    }

    public function getTemplate(string $id, string $type): string
    {
        return 'Template content';
    }

    public function getDoc(): string
    {
        return 'Doc content';
    }

    public function getDocTemplate(): string
    {
        return 'Doc template content';
    }

    public function getDocTemplate2(): string
    {
        return 'Doc template 2 content';
    }

    public function getDocTemplate3(): string
    {
        return 'Doc template 3 content';
    }
}
