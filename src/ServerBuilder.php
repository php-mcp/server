<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\Model\Capabilities;
use PhpMcp\Server\State\ClientStateManager;
use PhpMcp\Server\Support\HandlerResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Throwable;

final class ServerBuilder
{
    private ?string $name = null;

    private ?string $version = null;

    private ?Capabilities $capabilities = null;

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $cache = null;

    private ?ContainerInterface $container = null;

    private ?LoopInterface $loop = null;

    private ?int $definitionCacheTtl = 3600;

    private ?int $paginationLimit = 50;

    // Temporary storage for manual registrations
    private array $manualTools = [];

    private array $manualResources = [];

    private array $manualResourceTemplates = [];

    private array $manualPrompts = [];

    public function __construct() {}

    /**
     * Sets the server's identity. Required.
     */
    public function withServerInfo(string $name, string $version): self
    {
        $this->name = trim($name);
        $this->version = trim($version);

        return $this;
    }

    /**
     * Configures the server's declared capabilities.
     */
    public function withCapabilities(Capabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * Configures the server's pagination limit.
     */
    public function withPaginationLimit(int $paginationLimit): self
    {
        $this->paginationLimit = $paginationLimit;

        return $this;
    }

    /**
     * Provides a PSR-3 logger instance. Defaults to NullLogger.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Provides a PSR-16 cache instance and optionally sets the TTL for definition caching.
     * If no cache is provided, definition caching is disabled (uses default FileCache if possible).
     */
    public function withCache(CacheInterface $cache, int $definitionCacheTtl = 3600): self
    {
        $this->cache = $cache;
        $this->definitionCacheTtl = $definitionCacheTtl > 0 ? $definitionCacheTtl : 3600;

        return $this;
    }

    /**
     * Provides a PSR-11 DI container, primarily for resolving user-defined handler classes.
     * Defaults to a basic internal container.
     */
    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Provides a ReactPHP Event Loop instance. Defaults to Loop::get().
     */
    public function withLoop(LoopInterface $loop): self
    {
        $this->loop = $loop;

        return $this;
    }

    /**
     * Manually registers a tool handler.
     */
    public function withTool(array|string $handler, ?string $name = null, ?string $description = null, array $annotations = []): self
    {
        $this->manualTools[] = compact('handler', 'name', 'description', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource handler.
     */
    public function withResource(array|string $handler, string $uri, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?int $size = null, array $annotations = []): self
    {
        $this->manualResources[] = compact('handler', 'uri', 'name', 'description', 'mimeType', 'size', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource template handler.
     */
    public function withResourceTemplate(array|string $handler, string $uriTemplate, ?string $name = null, ?string $description = null, ?string $mimeType = null, array $annotations = []): self
    {
        $this->manualResourceTemplates[] = compact('handler', 'uriTemplate', 'name', 'description', 'mimeType', 'annotations');

        return $this;
    }

    /**
     * Manually registers a prompt handler.
     */
    public function withPrompt(array|string $handler, ?string $name = null, ?string $description = null): self
    {
        $this->manualPrompts[] = compact('handler', 'name', 'description');

        return $this;
    }

    /**
     * Builds the fully configured Server instance.
     *
     * @throws ConfigurationException If required configuration is missing.
     */
    public function build(): Server
    {
        if ($this->name === null || $this->version === null || $this->name === '' || $this->version === '') {
            throw new ConfigurationException('Server name and version must be provided using withServerInfo().');
        }

        $loop = $this->loop ?? Loop::get();
        $cache = $this->cache;
        $logger = $this->logger ?? new NullLogger();
        $container = $this->container ?? new BasicContainer();
        $capabilities = $this->capabilities ?? Capabilities::forServer();

        $configuration = new Configuration(
            serverName: $this->name,
            serverVersion: $this->version,
            capabilities: $capabilities,
            logger: $logger,
            loop: $loop,
            cache: $cache,
            container: $container,
            definitionCacheTtl: $this->definitionCacheTtl ?? 3600,
            paginationLimit: $this->paginationLimit ?? 50
        );

        $clientStateManager = new ClientStateManager($configuration->logger, $configuration->cache, 'mcp_state_', $configuration->definitionCacheTtl);
        $registry = new Registry($configuration->logger, $configuration->cache, $clientStateManager);
        $protocol = new Protocol($configuration, $registry, $clientStateManager);

        $this->performManualRegistrations($registry, $configuration->logger);

        $server = new Server($configuration, $registry, $protocol, $clientStateManager);

        return $server;
    }

    /**
     * Helper to perform the actual registration based on stored data.
     * Moved into the builder.
     */
    private function performManualRegistrations(Registry $registry, LoggerInterface $logger): void
    {
        if (empty($this->manualTools) && empty($this->manualResources) && empty($this->manualResourceTemplates) && empty($this->manualPrompts)) {
            return;
        }

        $errorCount = 0;
        $docBlockParser = new Support\DocBlockParser($logger);
        $schemaGenerator = new Support\SchemaGenerator($docBlockParser);

        // Register Tools
        foreach ($this->manualTools as $data) {
            try {
                $resolvedHandler = HandlerResolver::resolve($data['handler']);
                $def = Definitions\ToolDefinition::fromReflection(
                    $resolvedHandler['reflectionMethod'],
                    $data['name'],
                    $data['description'],
                    $data['annotations'],
                    $docBlockParser,
                    $schemaGenerator
                );
                $registry->registerTool($def, true);
                $logger->debug("Registered manual tool '{$def->getName()}' from handler {$resolvedHandler['className']}::{$resolvedHandler['methodName']}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual tool', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
            }
        }

        // Register Resources
        foreach ($this->manualResources as $data) {
            try {
                $resolvedHandler = HandlerResolver::resolve($data['handler']);
                $def = Definitions\ResourceDefinition::fromReflection(
                    $resolvedHandler['reflectionMethod'],
                    $data['name'],
                    $data['description'],
                    $data['uri'],
                    $data['mimeType'],
                    $data['size'],
                    $data['annotations'],
                    $docBlockParser
                );
                $registry->registerResource($def, true);
                $logger->debug("Registered manual resource '{$def->getUri()}' from handler {$resolvedHandler['className']}::{$resolvedHandler['methodName']}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual resource', ['handler' => $data['handler'], 'uri' => $data['uri'], 'exception' => $e]);
            }
        }

        // Register Templates
        foreach ($this->manualResourceTemplates as $data) {
            try {
                $resolvedHandler = HandlerResolver::resolve($data['handler']);
                $def = Definitions\ResourceTemplateDefinition::fromReflection(
                    $resolvedHandler['reflectionMethod'],
                    $data['name'],
                    $data['description'],
                    $data['uriTemplate'],
                    $data['mimeType'],
                    $data['annotations'],
                    $docBlockParser
                );
                $registry->registerResourceTemplate($def, true);
                $logger->debug("Registered manual template '{$def->getUriTemplate()}' from handler {$resolvedHandler['className']}::{$resolvedHandler['methodName']}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual template', ['handler' => $data['handler'], 'uriTemplate' => $data['uriTemplate'], 'exception' => $e]);
            }
        }

        // Register Prompts
        foreach ($this->manualPrompts as $data) {
            try {
                $resolvedHandler = HandlerResolver::resolve($data['handler']);
                $def = Definitions\PromptDefinition::fromReflection(
                    $resolvedHandler['reflectionMethod'],
                    $data['name'],
                    $data['description'],
                    $docBlockParser
                );
                $registry->registerPrompt($def, true);
                $logger->debug("Registered manual prompt '{$def->getName()}' from handler {$resolvedHandler['className']}::{$resolvedHandler['methodName']}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual prompt', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
            }
        }

        if ($errorCount > 0) {
            throw new DefinitionException("{$errorCount} error(s) occurred during manual element registration. Check logs.");
        }

        $logger->debug('Manual element registration complete.');
    }
}
