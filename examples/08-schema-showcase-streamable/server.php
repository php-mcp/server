#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP Schema Showcase Server (Attribute Discovery)
    |--------------------------------------------------------------------------
    |
    | This server demonstrates various ways to use the Schema attribute to
    | validate tool inputs. It showcases string constraints, numeric validation,
    | object schemas, array handling, enums, and format validation.
    |
    | To Use:
    | 1. Ensure 'SchemaShowcaseElements.php' defines classes with MCP attributes.
    | 2. Configure your MCP Client (e.g., Cursor) for this server:
    |
    | {
    |     "mcpServers": {
    |         "php-schema-showcase": {
    |             "command": "php",
    |             "args": ["/full/path/to/examples/08-schema-showcase-stdio/server.php"]
    |         }
    |     }
    | }
    |
    | This example focuses specifically on demonstrating different Schema
    | attribute capabilities for robust input validation.
    |
*/

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once 'SchemaShowcaseElements.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
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
    $logger->info('Starting MCP Schema Showcase Server...');

    $server = Server::make()
        ->withServerInfo('Schema Showcase', '1.0.0')
        ->withLogger($logger)
        ->build();

    $server->discover(__DIR__, ['.']);

    $transport = new StreamableHttpServerTransport('127.0.0.1', 8080, 'mcp');

    $server->listen($transport);

    $logger->info('Server listener stopped gracefully.');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n");
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
