#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP Stdio Calculator Server (Attribute Discovery)
    |--------------------------------------------------------------------------
    |
    | This server demonstrates using attribute-based discovery to find MCP
    | elements (Tools, Resources) in the 'McpElements.php' file within this
    | directory. It runs via the STDIO transport.
    |
    | To Use:
    | 1. Ensure 'McpElements.php' defines classes with MCP attributes.
    | 2. Configure your MCP Client (e.g., Cursor) for this server:
    |
    | {
    |     "mcpServers": {
    |         "php-stdio-calculator": {
    |             "command": "php",
    |             "args": ["/full/path/to/examples/01-discovery-stdio-calculator/server.php"]
    |         }
    |     }
    | }
    |
    | The ServerBuilder builds the server instance, then $server->discover()
    | scans the current directory (specified by basePath: __DIR__, scanDirs: ['.'])
    | to find and register elements before listening on STDIN/STDOUT.
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

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Log\AbstractLogger;

class StderrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf(
            "[%s] %s %s\n",
            strtoupper($level),
            $message,
            empty($context) ? '' : json_encode($context)
        ));
    }
}

try {
    $logger = new StderrLogger();
    $logger->info('Starting MCP Stdio Calculator Server...');

    $server = Server::make()
        ->withServerInfo('Stdio Calculator', '1.1.0')
        ->withLogger($logger)
        ->build();

    $server->discover(__DIR__, ['.']);

    $transport = new StdioServerTransport();

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
