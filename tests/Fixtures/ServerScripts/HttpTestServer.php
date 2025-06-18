#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\HttpServerTransport;
use PhpMcp\Server\Tests\Fixtures\General\ToolHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\PromptHandlerFixture;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

class StdErrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s] HTTP_SERVER_LOG: %s %s\n", strtoupper((string)$level), $message, empty($context) ? '' : json_encode($context)));
    }
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 8990);
$mcpPathPrefix = $argv[3] ?? 'mcp_http_test';

try {
    $logger = new NullLogger();

    $server = Server::make()
        ->withServerInfo('HttpIntegrationTestServer', '0.1.0')
        ->withLogger($logger)
        ->withTool([ToolHandlerFixture::class, 'greet'], 'greet_http_tool')
        ->withResource([ResourceHandlerFixture::class, 'getStaticText'], "test://http/static", 'static_http_resource')
        ->withPrompt([PromptHandlerFixture::class, 'generateSimpleGreeting'], 'simple_http_prompt')
        ->build();

    $transport = new HttpServerTransport($host, $port, $mcpPathPrefix);
    $server->listen($transport);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[HTTP_SERVER_CRITICAL_ERROR]\nHost:{$host} Port:{$port} Prefix:{$mcpPathPrefix}\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
