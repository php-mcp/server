<?php declare(strict_types = 1);
namespace PhpMcp\Server;

use PhpMcp\Server\Contracts\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Context
{
    public function __construct(
        public readonly ?ServerRequestInterface $request,
        public readonly SessionInterface $session
    )
    {
    }
}