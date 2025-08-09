<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Middlewares;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class ShortCircuitMiddleware
{
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        if (str_contains($request->getUri()->getPath(), '/short-circuit')) {
            return new Response(418, [], 'Short-circuited by middleware');
        }
        return $next($request);
    }
}
