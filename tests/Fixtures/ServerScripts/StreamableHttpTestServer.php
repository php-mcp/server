#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use PhpMcp\Server\Tests\Fixtures\General\ToolHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\PromptHandlerFixture;
use PhpMcp\Server\Defaults\InMemoryEventStore;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

class StdErrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s] SERVER_LOG: %s %s\n", strtoupper((string)$level), $message, empty($context) ? '' : json_encode($context)));
    }
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 8992);
$mcpPath = $argv[3] ?? 'mcp_streamable_test';
$enableJsonResponse = filter_var($argv[4] ?? 'true', FILTER_VALIDATE_BOOLEAN);
$useEventStore = filter_var($argv[5] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$stateless = filter_var($argv[6] ?? 'false', FILTER_VALIDATE_BOOLEAN);

try {
    $logger = new NullLogger();
    $logger->info("Starting StreamableHttpTestServer on {$host}:{$port}/{$mcpPath}, JSON Mode: " . ($enableJsonResponse ? 'ON' : 'OFF') . ", Stateless: " . ($stateless ? 'ON' : 'OFF'));

    $eventStore = $useEventStore ? new InMemoryEventStore() : null;

    $server = Server::make()
        ->withServerInfo('StreamableHttpIntegrationServer', '0.1.0')
        ->withLogger($logger)
        ->withTool([ToolHandlerFixture::class, 'greet'], 'greet_streamable_tool')
        ->withTool([ToolHandlerFixture::class, 'sum'], 'sum_streamable_tool') // For batch testing
        ->withResource([ResourceHandlerFixture::class, 'getStaticText'], "test://streamable/static", 'static_streamable_resource')
        ->withPrompt([PromptHandlerFixture::class, 'generateSimpleGreeting'], 'simple_streamable_prompt')
        ->build();

    $transport = new StreamableHttpServerTransport(
        host: $host,
        port: $port,
        mcpPath: $mcpPath,
        enableJsonResponse: $enableJsonResponse,
        stateless: $stateless,
        eventStore: $eventStore
    );

    $server->listen($transport);

    $logger->info("StreamableHttpTestServer listener stopped on {$host}:{$port}.");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[STREAMABLE_HTTP_SERVER_CRITICAL_ERROR]\nHost:{$host} Port:{$port} Prefix:{$mcpPath}\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
