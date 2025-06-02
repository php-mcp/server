#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP HTTP Server (Combined Manual & Discovered Elements)
    |--------------------------------------------------------------------------
    |
    | This server demonstrates a combination of manual element registration
    | via the ServerBuilder and attribute-based discovery.
    | - Manually registered elements are defined in 'ManualHandlers.php'.
    | - Discoverable elements are in 'DiscoveredElements.php'.
    |
    | It runs via the HTTP transport.
    |
    | This example also shows precedence: if a manually registered element
    | has the same identifier (e.g., URI for a resource, or name for a tool)
    | as a discovered one, the manual registration takes priority.
    |
    | To Use:
    | 1. Run this script from your CLI: `php server.php`
    |    The server will listen on http://127.0.0.1:8081 by default.
    | 2. Configure your MCP Client (e.g., Cursor):
    |
    | {
    |     "mcpServers": {
    |         "php-http-combined": {
    |             "url": "http://127.0.0.1:8081/mcp_combined/sse" // Note the prefix
    |         }
    |     }
    | }
    |
    | Manual elements are registered during ServerBuilder->build().
    | Then, $server->discover() scans for attributed elements.
    |
*/

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once './DiscoveredElements.php';
require_once './ManualHandlers.php';

use Mcp\CombinedHttpExample\Manual\ManualHandlers;
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
    $logger->info('Starting MCP Combined Registration (HTTP) Server...');

    $container = new BasicContainer();
    $container->set(LoggerInterface::class, $logger); // ManualHandlers needs LoggerInterface

    $server = Server::make()
        ->withServerInfo('Combined HTTP Server', '1.0.0')
        ->withLogger($logger)
        ->withContainer($container)
        ->withTool([ManualHandlers::class, 'manualGreeter'])
        ->withResource(
            [ManualHandlers::class, 'getPriorityConfigManual'],
            'config://priority',
            'priority_config_manual',
        )
        ->build();

    // Now, run discovery. Discovered elements will be added.
    // If 'config://priority' was discovered, the manual one takes precedence.
    $server->discover(__DIR__, scanDirs: ['.']);

    $transport = new HttpServerTransport('127.0.0.1', 8081, 'mcp_combined');

    $server->listen($transport);

    $logger->info('Server listener stopped gracefully.');
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n".$e."\n");
    exit(1);
}
