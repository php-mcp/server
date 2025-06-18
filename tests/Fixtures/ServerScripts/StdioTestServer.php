#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use PhpMcp\Server\Tests\Fixtures\General\ToolHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\PromptHandlerFixture;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

class StdErrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s] SERVER_LOG: %s %s\n", strtoupper((string)$level), $message, empty($context) ? '' : json_encode($context)));
    }
}

try {
    $logger = new NullLogger();
    $logger->info('StdioTestServer listener starting.');

    $server = Server::make()
        ->withServerInfo('StdioIntegrationTestServer', '0.1.0')
        ->withLogger($logger)
        ->withTool([ToolHandlerFixture::class, 'greet'], 'greet_stdio_tool')
        ->withResource([ResourceHandlerFixture::class, 'getStaticText'], 'test://stdio/static', 'static_stdio_resource')
        ->withPrompt([PromptHandlerFixture::class, 'generateSimpleGreeting'], 'simple_stdio_prompt')
        ->build();

    $transport = new StdioServerTransport();
    $server->listen($transport);

    $logger->info('StdioTestServer listener stopped.');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[STDIO_SERVER_CRITICAL_ERROR]\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
