<?php

declare(strict_types=1);

use PhpMcp\Server\Defaults\StreamLogger;
use PhpMcp\Server\Server;

// --- Instructions ---
// 1. composer install
// 2. Add this script to any MCP client with config that looks like this:
//    {
//        "mcpServers": {
//            "workbench": {
//                "command": "php",
//                "args": ["/path/to/server.php"]
//            }
//        }
//    }
// --- ------------ ---

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/SampleMcpElements.php';

$logger = new StreamLogger(__DIR__.'/mcp.log', 'debug');

$server = Server::make()
    ->withLogger($logger)
    ->withBasePath(__DIR__)
    ->discover();

$exitCode = $server->run('stdio');

exit($exitCode);
