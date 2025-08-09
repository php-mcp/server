<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Middlewares;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;

class HeaderMiddleware
{
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        $result = $next($request);

        return match (true) {
            $result instanceof PromiseInterface => $result->then(fn($response) => $this->handle($response)),
            $result instanceof ResponseInterface => $this->handle($result),
            default => $result
        };
    }

    private function handle($response)
    {
        return $response instanceof ResponseInterface
            ? $response->withHeader('X-Test-Middleware', 'header-added')
            : $response;
    }
}
