<?php declare(strict_types = 1);
namespace PhpMcp\Server;

use PhpMcp\Server\Contracts\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Context
{
    public function __construct(
        public readonly SessionInterface $session,
        public readonly ?ServerRequestInterface $request = null,
    )
    {
    }
}