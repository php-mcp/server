#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__);
require_once '../../vendor/autoload.php';
require_once './EnvToolHandler.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Log\AbstractLogger;

/*
    |--------------------------------------------------------------------------
    | MCP Stdio Environment Variable Example Server
    |--------------------------------------------------------------------------
    |
    | This server demonstrates how to use environment variables to modify tool
    | behavior. The MCP client can set the APP_MODE environment variable to
    | control the server's behavior.
    |
    | Configure your MCP Client (eg. Cursor) for this server like this:
    |
    | {
    |     "mcpServers": {
    |         "my-php-env-server": {
    |             "command": "php",
    |             "args": ["/full/path/to/examples/05-stdio-env-variables/server.php"],
    |             "env": {
    |                 "APP_MODE": "debug" // or "production", or leave it out
    |             }
    |         }
    |     }
    | }
    |
    | The server will read the APP_MODE environment variable and use it to
    | modify the behavior of the tools.
    |
    | If the APP_MODE environment variable is not set, the server will use the
    | default behavior.
    |
*/

class StderrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s][%s] %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message, empty($context) ? '' : json_encode($context)));
    }
}

try {
    $logger = new StderrLogger();
    $logger->info('Starting MCP Stdio Environment Variable Example Server...');

    $server = Server::make()
        ->withServerInfo('Env Var Server', '1.0.0')
        ->withLogger($logger)
        ->build();

    $server->discover(__DIR__, ['.']);

    $transport = new StdioServerTransport();
    $server->listen($transport);

    $logger->info('Server listener stopped gracefully.');
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n".$e."\n");
    exit(1);
}
