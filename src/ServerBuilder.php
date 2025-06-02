<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\Model\Capabilities;
use PhpMcp\Server\State\ClientStateManager;
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

    private ?int $definitionCacheTtl = 3600; // Default TTL

    // Temporary storage for manual registrations
    private array $manualTools = [];

    private array $manualResources = [];

    private array $manualResourceTemplates = [];

    private array $manualPrompts = [];

    public function __construct()
    {
    }

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
    public function withTool(array|string $handler, ?string $name = null, ?string $description = null): self
    {
        $this->manualTools[] = compact('handler', 'name', 'description');

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
            definitionCacheTtl: $this->definitionCacheTtl ?? 3600
        );

        $clientStateManager = new ClientStateManager($configuration->logger, $configuration->cache, 'mcp_state_', $configuration->definitionCacheTtl);
        $registry = new Registry($configuration->logger, $configuration->cache, $clientStateManager);
        $protocol = new Protocol($configuration, $registry, $clientStateManager);

        $this->performManualRegistrations($registry, $configuration->logger);

        $server = new Server($configuration, $registry, $protocol);

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
                $methodRefl = $this->validateAndGetReflectionMethod($data['handler']);
                $def = Definitions\ToolDefinition::fromReflection(
                    $methodRefl,
                    $data['name'],
                    $data['description'],
                    $docBlockParser,
                    $schemaGenerator
                );
                $registry->registerTool($def, true);
                $logger->debug("Registered manual tool '{$def->getName()}'");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual tool', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
            }
        }

        // Register Resources
        foreach ($this->manualResources as $data) {
            try {
                $methodRefl = $this->validateAndGetReflectionMethod($data['handler']);
                $def = Definitions\ResourceDefinition::fromReflection(
                    $methodRefl,
                    $data['name'],
                    $data['description'],
                    $data['uri'],
                    $data['mimeType'],
                    $data['size'],
                    $data['annotations'],
                    $docBlockParser
                );
                $registry->registerResource($def, true);
                $logger->debug("Registered manual resource '{$def->getUri()}'");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual resource', ['handler' => $data['handler'], 'uri' => $data['uri'], 'exception' => $e]);
            }
        }

        // Register Templates
        foreach ($this->manualResourceTemplates as $data) {
            try {
                $methodRefl = $this->validateAndGetReflectionMethod($data['handler']);
                $def = Definitions\ResourceTemplateDefinition::fromReflection(
                    $methodRefl,
                    $data['name'],
                    $data['description'],
                    $data['uriTemplate'],
                    $data['mimeType'],
                    $data['annotations'],
                    $docBlockParser
                );
                $registry->registerResourceTemplate($def, true);
                $logger->debug("Registered manual template '{$def->getUriTemplate()}'");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual template', ['handler' => $data['handler'], 'uriTemplate' => $data['uriTemplate'], 'exception' => $e]);
            }
        }

        // Register Prompts
        foreach ($this->manualPrompts as $data) {
            try {
                $methodRefl = $this->validateAndGetReflectionMethod($data['handler']);
                $def = Definitions\PromptDefinition::fromReflection(
                    $methodRefl,
                    $data['name'],
                    $data['description'],
                    $docBlockParser
                );
                $registry->registerPrompt($def, true);
                $logger->debug("Registered manual prompt '{$def->getName()}'");
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

    /**
     * Gets a reflection method from a handler.
     *
     * @throws \InvalidArgumentException If the handler is invalid.
     */
    private function validateAndGetReflectionMethod(array|string $handler): \ReflectionMethod
    {
        $className = null;
        $methodName = null;

        if (is_array($handler)) {
            if (count($handler) !== 2 || ! is_string($handler[0]) || ! is_string($handler[1])) {
                throw new \InvalidArgumentException('Invalid array handler format. Expected [ClassName::class, \'methodName\'].');
            }
            [$className, $methodName] = $handler;
            if (! class_exists($className)) {
                throw new \InvalidArgumentException("Class '{$className}' not found for array handler.");
            }
            if (! method_exists($className, $methodName)) {
                throw new \InvalidArgumentException("Method '{$methodName}' not found in class '{$className}' for array handler.");
            }
        } elseif (is_string($handler) && class_exists($handler)) {
            $className = $handler;
            $methodName = '__invoke';
            if (! method_exists($className, $methodName)) {
                throw new \InvalidArgumentException("Invokable class '{$className}' must have a public '__invoke' method.");
            }
        } else {
            throw new \InvalidArgumentException('Invalid handler format. Expected [ClassName::class, \'methodName\'] or InvokableClassName::class string.');
        }

        try {
            $reflectionMethod = new \ReflectionMethod($className, $methodName);
            if ($reflectionMethod->isStatic()) {
                throw new \InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be static.");
            }
            if (! $reflectionMethod->isPublic()) {
                throw new \InvalidArgumentException("Handler method '{$className}::{$methodName}' must be public.");
            }
            if ($reflectionMethod->isAbstract()) {
                throw new \InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be abstract.");
            }
            if ($reflectionMethod->isConstructor() || $reflectionMethod->isDestructor()) {
                throw new \InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be a constructor or destructor.");
            }

            return $reflectionMethod;
        } catch (\ReflectionException $e) {
            throw new \InvalidArgumentException("Reflection error for handler '{$className}::{$methodName}': {$e->getMessage()}", 0, $e);
        }
    }
}
