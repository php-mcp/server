<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Middlewares;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;

class ThirdMiddleware
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
        if ($response instanceof ResponseInterface) {
            $existing = $response->getHeaderLine('X-Middleware-Order');
            $new = $existing ? $existing . ',third' : 'third';
            return $response->withHeader('X-Middleware-Order', $new);
        }
        return $response;
    }
}
