#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP HTTP Server with Complex Tool Schema (Event Scheduler)
    |--------------------------------------------------------------------------
    |
    | This example demonstrates how to define an MCP Tool with a more complex
    | input schema, utilizing various PHP types, optional parameters, default
    | values, and backed Enums. The server automatically generates the
    | corresponding JSON Schema for the tool's input.
    |
    | Scenario:
    | An "Event Scheduler" tool that allows scheduling events with details like
    | title, date, time (optional), type (enum), priority (enum with default),
    | attendees (optional list), and invite preferences (boolean with default).
    |
    | Key Points:
    |   - The `schedule_event` tool in `McpEventScheduler.php` showcases:
    |       - Required string parameters (`title`, `date`).
    |       - A required backed string enum parameter (`EventType $type`).
    |       - Optional nullable string (`?string $time = null`).
    |       - Optional backed integer enum with a default value (`EventPriority $priority = EventPriority::Normal`).
    |       - Optional nullable array of strings (`?array $attendees = null`).
    |       - Optional boolean with a default value (`bool $sendInvites = true`).
    |   - PHP type hints and default values are used by `SchemaGenerator` (internal)
    |     to create the `inputSchema` for the tool.
    |   - This example uses attribute-based discovery and the HTTP transport.
    |
    | To Use:
    | 1. Run this script: `php server.php` (from this directory)
    |    The server will listen on http://127.0.0.1:8082 by default.
    | 2. Configure your MCP Client (e.g., Cursor) for this server:
    |
    | {
    |     "mcpServers": {
    |         "php-http-complex-scheduler": {
    |             "url": "http://127.0.0.1:8082/mcp_scheduler/sse" // Note the prefix
    |         }
    |     }
    | }
    |
    | Connect your client, list tools, and inspect the 'inputSchema' for the
    | 'schedule_event' tool. Prompt your LLM with question to test the tool.
    |
*/

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once './EventTypes.php'; // Include enums
require_once './McpEventScheduler.php';

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
    $logger->info('Starting MCP Complex Schema HTTP Server...');

    $container = new BasicContainer();
    $container->set(LoggerInterface::class, $logger);

    $server = Server::make()
        ->withServerInfo('Event Scheduler Server', '1.0.0')
        ->withLogger($logger)
        ->withContainer($container)
        ->build();

    $server->discover(__DIR__, ['.']);

    $transport = new HttpServerTransport('127.0.0.1', 8082, 'mcp_scheduler');
    $server->listen($transport);

    $logger->info('Server listener stopped gracefully.');
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n".$e."\n");
    exit(1);
}
