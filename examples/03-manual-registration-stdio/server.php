#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP Stdio Server (Manual Element Registration)
    |--------------------------------------------------------------------------
    |
    | This server demonstrates how to manually register all MCP elements
    | (Tools, Resources, Prompts, ResourceTemplates) using the ServerBuilder's
    | fluent `withTool()`, `withResource()`, etc., methods.
    | It does NOT use attribute discovery. Handlers are in 'SimpleHandlers.php'.
    | It runs via the STDIO transport.
    |
    | To Use:
    | 1. Configure your MCP Client (e.g., Cursor) for this server:
    |
    | {
    |     "mcpServers": {
    |         "php-stdio-manual": {
    |             "command": "php",
    |             "args": ["/full/path/to/examples/03-manual-registration-stdio/server.php"]
    |         }
    |     }
    | }
    |
    | All elements are explicitly defined during the ServerBuilder chain.
    | The $server->discover() method is NOT called.
    |
*/

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once './SimpleHandlers.php';

use Mcp\ManualStdioExample\SimpleHandlers;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
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
    $logger->info('Starting MCP Manual Registration (Stdio) Server...');

    $container = new BasicContainer();
    $container->set(LoggerInterface::class, $logger);

    $server = Server::make()
        ->withServerInfo('Manual Reg Server', '1.0.0')
        ->withLogger($logger)
        ->withContainer($container)
        ->withTool([SimpleHandlers::class, 'echoText'], 'echo_text')
        ->withResource([SimpleHandlers::class, 'getAppVersion'], 'app://version', 'application_version', mimeType: 'text/plain')
        ->withPrompt([SimpleHandlers::class, 'greetingPrompt'], 'personalized_greeting')
        ->withResourceTemplate([SimpleHandlers::class, 'getItemDetails'], 'item://{itemId}/details', 'get_item_details', mimeType: 'application/json')
        ->build();

    $transport = new StdioServerTransport();
    $server->listen($transport);

    $logger->info('Server listener stopped gracefully.');
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n".$e."\n");
    exit(1);
}
