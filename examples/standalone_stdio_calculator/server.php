#!/usr/bin/env php
<?php

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
