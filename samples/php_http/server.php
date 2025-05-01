<?php

declare(strict_types=1);

use PhpMcp\Server\Defaults\StreamLogger;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\HttpTransportHandler;

// --- Instructions ---
// 1. composer install
// 2. Run: php -S localhost:8080 server.php
// 3. Use an MCP client (like an Extension) to connect via HTTP SSE:
//    - SSE Endpoint: http://localhost:8080/mcp/sse
//    - POST Endpoint will be provided by the server via the 'endpoint' event.
// --- ------------ ---

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/SampleMcpElements.php';

// --- MCP Server Setup ---
$logger = new StreamLogger(__DIR__.'/vanilla_server.log', 'debug');
$server = Server::make()
    ->withLogger($logger)
    ->withBasePath(__DIR__)
    ->discover();

$httpHandler = new HttpTransportHandler($server);

// --- Basic Routing & Client ID ---
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$queryParams = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $queryParams);

$postEndpoint = '/mcp/message';
$sseEndpoint = '/mcp/sse';

$logger->info('Request received', ['method' => $method, 'path' => $path]);

// --- POST Endpoint Handling ---
if ($method === 'POST' && str_starts_with($path, $postEndpoint)) {
    $clientId = $queryParams['clientId'] ?? null;
    if (! $clientId || ! is_string($clientId)) {
        http_response_code(400);
        echo 'Error: Missing or invalid clientId query parameter';
        exit;
    }
    $logger->info('POST Processing', ['client_id' => $clientId]);

    if (! str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        http_response_code(415);
        echo 'Error: Content-Type must be application/json';
        exit;
    }
    $requestBody = file_get_contents('php://input');
    if ($requestBody === false || empty($requestBody)) {
        http_response_code(400);
        echo 'Error: Empty request body';
        exit;
    }

    try {
        $httpHandler->handleInput($requestBody, $clientId);
        http_response_code(202); // Accepted
    } catch (JsonException $e) {
        http_response_code(400);
        echo 'Error: Invalid JSON';
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Error: Internal Server Error';
    }
    exit;
}

// --- SSE Endpoint Handling ---
if ($method === 'GET' && $path === $sseEndpoint) {
    // Generate a unique ID for this SSE connection
    $clientId = 'client_'.bin2hex(random_bytes(16));
    $logger->info('SSE connection opening', ['client_id' => $clientId]);

    ignore_user_abort(true);
    set_time_limit(0);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    try {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080'; // Guess host/port
        $postEndpointWithClientId = $postEndpoint.'?clientId='.urlencode($clientId);

        // Use the default callback within the handler
        $httpHandler->handleSseConnection($clientId, $postEndpointWithClientId);
    } catch (Throwable $e) {
        // Log errors, excluding potential disconnect exceptions if needed
        if (! ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'disconnected'))) {
            $logger->error('SSE stream loop terminated', ['client_id' => $clientId, 'reason' => $e->getMessage()]);
        }
    } finally {
        $httpHandler->cleanupClient($clientId);
        $logger->info('SSE connection closed and client cleaned up', ['client_id' => $clientId]);
    }
    exit;
}

// --- Fallback 404 ---
http_response_code(404);
echo 'Not Found';
$logger->warning('404 Not Found', ['method' => $method, 'path' => $path]);
