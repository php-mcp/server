<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Middlewares;

use Psr\Http\Message\ServerRequestInterface;

class RequestAttributeMiddleware
{
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        $request = $request->withAttribute('middleware-attr', 'middleware-value');
        return $next($request);
    }
}
