#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP HTTP User Profile Server (Attribute Discovery)
    |--------------------------------------------------------------------------
    |
    | This server demonstrates attribute-based discovery for MCP elements
    | (ResourceTemplates, Resources, Tools, Prompts) defined in 'McpElements.php'.
    | It runs via the HTTP transport, listening for SSE and POST requests.
    |
    | To Use:
    | 1. Ensure 'McpElements.php' defines classes with MCP attributes.
    | 2. Run this script from your CLI: `php server.php`
    |    The server will listen on http://127.0.0.1:8080 by default.
    | 3. Configure your MCP Client (e.g., Cursor) for this server:
    |
    | {
    |     "mcpServers": {
    |         "php-http-userprofile": {
    |             "url": "http://127.0.0.1:8080/mcp/sse" // Use the SSE endpoint
    |             // Ensure your client can reach this address
    |         }
    |     }
    | }
    |
    | The ServerBuilder builds the server, $server->discover() scans for elements,
    | and then $server->listen() starts the ReactPHP HTTP server.
    |
    | If you provided a `CacheInterface` implementation to the ServerBuilder,
    | the discovery process will be cached, so you can comment out the
    | discovery call after the first run to speed up subsequent runs.
    |
*/

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once 'McpElements.php';

use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\HttpServerTransport;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class StderrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s][%s] %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message, empty($context) ? '' : json_encode($context)));
    }
}

try {
    $logger = new StderrLogger();
    $logger->info('Starting MCP HTTP User Profile Server...');

    // --- Setup DI Container for DI in McpElements class ---
    $container = new BasicContainer();
    $container->set(LoggerInterface::class, $logger);

    $server = Server::make()
        ->withServerInfo('HTTP User Profiles', '1.0.0')
        ->withLogger($logger)
        ->withContainer($container)
        ->build();

    $server->discover(__DIR__, ['.']);

    $transport = new HttpServerTransport(
        host: '127.0.0.1',
        port: 8080,
        mcpPathPrefix: 'mcp'
    );

    $server->listen($transport);

    $logger->info('Server listener stopped gracefully.');
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n");
    fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
    fwrite(STDERR, 'File: '.$e->getFile().':'.$e->getLine()."\n");
    fwrite(STDERR, $e->getTraceAsString()."\n");
    exit(1);
}
