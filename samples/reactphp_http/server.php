<?php

declare(strict_types=1);

use PhpMcp\Server\Defaults\StreamLogger;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\ReactPhpHttpTransportHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

// --- Instructions ---
// 1. cd php-mcp/samples/reactphp
// 2. composer install
// 3. Run: php server.php
// 4. Use an MCP client to connect via HTTP SSE:
//    - SSE Endpoint: http://127.0.0.1:8080/mcp/sse
//    - POST Endpoint will be provided by the server via the 'endpoint' event.
// --- ------------ ---

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/SampleMcpElements.php';

// --- MCP Server Setup ---
$logger = new StreamLogger(__DIR__.'/react_server.log', 'debug');

$server = Server::make()
    ->withLogger($logger)
    ->withBasePath(__DIR__)
    ->discover();

$transportHandler = new ReactPhpHttpTransportHandler($server);

// --- ReactPHP HTTP Server Setup ---
$postEndpoint = '/mcp/message';
$sseEndpoint = '/mcp/sse';
$listenAddress = '127.0.0.1:8080';

$http = new HttpServer(function (ServerRequestInterface $request) use ($logger, $transportHandler, $postEndpoint, $sseEndpoint): ResponseInterface|Promise {
    $path = $request->getUri()->getPath();
    $method = $request->getMethod();
    $responseHeaders = ['Access-Control-Allow-Origin' => '*'];

    $logger->info('Received request', ['method' => $method, 'path' => $path]);

    // POST Endpoint Handling
    if ($method === 'POST' && str_starts_with($path, $postEndpoint)) {
        $queryParams = $request->getQueryParams();
        $clientId = $queryParams['clientId'] ?? null;

        if (! $clientId || ! is_string($clientId)) {
            return new Response(400, $responseHeaders, 'Error: Missing or invalid clientId query parameter');
        }
        if (! str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return new Response(415, $responseHeaders, 'Error: Content-Type must be application/json');
        }

        $requestBody = (string) $request->getBody();
        if (empty($requestBody)) {
            return new Response(400, $responseHeaders, 'Error: Empty request body');
        }

        try {
            $transportHandler->handleInput($requestBody, $clientId);

            return new Response(202, $responseHeaders); // Accepted
        } catch (JsonException $e) {
            return new Response(400, $responseHeaders, "Error: Invalid JSON - {$e->getMessage()}");
        } catch (Throwable $e) {
            return new Response(500, $responseHeaders, 'Error: Internal Server Error');
        }
    }

    // SSE Endpoint Handling
    if ($method === 'GET' && $path === $sseEndpoint) {
        $clientId = 'client_'.bin2hex(random_bytes(16));

        $logger->info('ReactPHP SSE connection opening', ['client_id' => $clientId]);

        $stream = new ThroughStream;

        $postEndpointWithClientId = $postEndpoint.'?clientId='.urlencode($clientId);

        $transportHandler->setClientSseStream($clientId, $stream);

        $transportHandler->handleSseConnection($clientId, $postEndpointWithClientId);

        $sseHeaders = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => '*',
        ];

        return new Response(200, $sseHeaders, $stream);
    }

    // Fallback 404
    return new Response(404, $responseHeaders, 'Not Found');
});

$socket = new SocketServer($listenAddress);

$logger->info("ReactPHP MCP Server listening on {$listenAddress}");
$logger->info("SSE Endpoint: http://{$listenAddress}{$sseEndpoint}");
$logger->info("POST Endpoint: (Sent via SSE 'endpoint' event)");
echo "ReactPHP MCP Server listening on http://{$listenAddress}\n";

$http->listen($socket);
