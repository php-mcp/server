#!/usr/bin/env php
<?php

/*
    |--------------------------------------------------------------------------
    | MCP Stdio Server with Custom Dependencies (Task Manager)
    |--------------------------------------------------------------------------
    |
    | This example demonstrates how to use a PSR-11 Dependency Injection (DI)
    | container (PhpMcp\Server\Defaults\BasicContainer in this case) to inject
    | custom services (like a TaskRepositoryInterface or StatsServiceInterface)
    | into your MCP element handler classes.
    |
    | Scenario:
    | A simple Task Management system where:
    |   - Tools allow adding tasks, listing tasks for a user, and completing tasks.
    |   - A Resource provides system statistics (total tasks, pending, etc.).
    |   - Handlers in 'McpTaskHandlers.php' depend on service interfaces.
    |   - Concrete service implementations are in 'Services.php'.
    |
    | Key Points:
    |   - The `ServerBuilder` is configured with `->withContainer($container)`.
    |   - The DI container is set up with bindings for service interfaces to
    |     their concrete implementations (e.g., TaskRepositoryInterface -> InMemoryTaskRepository).
    |   - The `McpTaskHandlers` class receives its dependencies (TaskRepositoryInterface,
    |     StatsServiceInterface, LoggerInterface) via constructor injection, resolved by
    |     the DI container when the Processor needs an instance of McpTaskHandlers.
    |   - This example uses attribute-based discovery via `$server->discover()`.
    |   - It runs using the STDIO transport.
    |
    | To Use:
    | 1. Run this script: `php server.php` (from this directory)
    | 2. Configure your MCP Client (e.g., Cursor) for this server:
    |
    | {
    |     "mcpServers": {
    |         "php-stdio-deps-taskmgr": {
    |             "command": "php",
    |             "args": ["/full/path/to/examples/06-custom-dependencies-stdio/server.php"]
    |         }
    |     }
    | }
    |
    | Interact with tools like 'add_task', 'list_user_tasks', 'complete_task'
    | and read the resource 'stats://system/overview'.
    |
*/

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once './Services.php';
require_once './McpTaskHandlers.php';

use Mcp\DependenciesStdioExample\Services;
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
    $logger->info('Starting MCP Custom Dependencies (Stdio) Server...');

    $container = new BasicContainer();
    $container->set(LoggerInterface::class, $logger);

    $taskRepo = new Services\InMemoryTaskRepository($logger);
    $container->set(Services\TaskRepositoryInterface::class, $taskRepo);

    $statsService = new Services\SystemStatsService($taskRepo);
    $container->set(Services\StatsServiceInterface::class, $statsService);

    $server = Server::make()
        ->withServerInfo('Task Manager Server', '1.0.0')
        ->withLogger($logger)
        ->withContainer($container)
        ->build();

    $server->discover(__DIR__, ['.']);

    $transport = new StdioServerTransport();
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
