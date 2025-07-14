<?php declare(strict_types = 1);
namespace PhpMcp\Server;

use Psr\Http\Message\ServerRequestInterface;

class CallContext
{
    public ?ServerRequestInterface $request = null;
}