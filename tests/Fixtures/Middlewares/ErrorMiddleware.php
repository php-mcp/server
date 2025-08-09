<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Middlewares;

use Psr\Http\Message\ServerRequestInterface;

class ErrorMiddleware
{
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        if (str_contains($request->getUri()->getPath(), '/error-middleware')) {
            throw new \Exception('Middleware error');
        }
        return $next($request);
    }
}
