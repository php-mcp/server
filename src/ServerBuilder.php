<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use PhpMcp\Schema\Annotations;
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\Prompt;
use PhpMcp\Schema\PromptArgument;
use PhpMcp\Schema\Resource;
use PhpMcp\Schema\ResourceTemplate;
use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Schema\Tool;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\CompletionProvider;
use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\CacheSessionHandler;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Utils\HandlerResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Throwable;

final class ServerBuilder
{
    private ?Implementation $serverInfo = null;

    private ?ServerCapabilities $capabilities = null;

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $cache = null;

    private ?ContainerInterface $container = null;

    private ?LoopInterface $loop = null;

    private ?SessionHandlerInterface $sessionHandler = null;

    private ?int $sessionTtl = 3600;

    private ?int $definitionCacheTtl = 3600;

    private ?int $paginationLimit = 50;

    /** @var array<
     *     array{handler: array|string,
     *     name: string|null,
     *     description: string|null,
     *     annotations: ToolAnnotations|null}
     * > */
    private array $manualTools = [];

    /** @var array<
     *     array{handler: array|string,
     *     uri: string,
     *     name: string|null,
     *     description: string|null,
     *     mimeType: string|null,
     *     size: int|null,
     *     annotations: Annotations|null}
     * > */
    private array $manualResources = [];

    /** @var array<
     *     array{handler: array|string,
     *     uriTemplate: string,
     *     name: string|null,
     *     description: string|null,
     *     mimeType: string|null,
     *     annotations: Annotations|null}
     * > */
    private array $manualResourceTemplates = [];

    /** @var array<
     *     array{handler: array|string,
     *     name: string|null,
     *     description: string|null}
     * > */
    private array $manualPrompts = [];

    public function __construct() {}

    /**
     * Sets the server's identity. Required.
     */
    public function withServerInfo(string $name, string $version): self
    {
        $this->serverInfo = Implementation::make(name: trim($name), version: trim($version));

        return $this;
    }

    /**
     * Configures the server's declared capabilities.
     */
    public function withCapabilities(ServerCapabilities $capabilities): self
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

    public function withSessionHandler(SessionHandlerInterface $sessionHandler, int $sessionTtl = 3600): self
    {
        $this->sessionHandler = $sessionHandler;
        $this->sessionTtl = $sessionTtl;

        return $this;
    }

    public function withArraySessionHandler(int $sessionTtl = 3600): self
    {
        $this->sessionHandler = new ArraySessionHandler($sessionTtl);
        $this->sessionTtl = $sessionTtl;

        return $this;
    }

    public function withCacheSessionHandler(CacheInterface $cache, int $sessionTtl = 3600): self
    {
        $this->sessionHandler = new CacheSessionHandler($cache, $sessionTtl);
        $this->sessionTtl = $sessionTtl;

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
    public function withTool(array|string $handler, ?string $name = null, ?string $description = null, ?ToolAnnotations $annotations = null): self
    {
        $this->manualTools[] = compact('handler', 'name', 'description', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource handler.
     */
    public function withResource(array|string $handler, string $uri, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?int $size = null, ?Annotations $annotations = null): self
    {
        $this->manualResources[] = compact('handler', 'uri', 'name', 'description', 'mimeType', 'size', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource template handler.
     */
    public function withResourceTemplate(array|string $handler, string $uriTemplate, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?Annotations $annotations = null): self
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
        if ($this->serverInfo === null) {
            throw new ConfigurationException('Server name and version must be provided using withServerInfo().');
        }

        $loop = $this->loop ?? Loop::get();
        $cache = $this->cache;
        $logger = $this->logger ?? new NullLogger();
        $container = $this->container ?? new BasicContainer();
        $capabilities = $this->capabilities ?? ServerCapabilities::make();

        $configuration = new Configuration(
            serverInfo: $this->serverInfo,
            capabilities: $capabilities,
            logger: $logger,
            loop: $loop,
            cache: $cache,
            container: $container,
            definitionCacheTtl: $this->definitionCacheTtl ?? 3600,
            paginationLimit: $this->paginationLimit ?? 50
        );

        $sessionHandler = $this->sessionHandler ?? new ArraySessionHandler(3600);
        $sessionManager = new SessionManager($sessionHandler, $logger, $loop, $this->sessionTtl);
        $registry = new Registry($logger, $cache, $sessionManager);
        $protocol = new Protocol($configuration, $registry, $sessionManager);

        $registry->disableNotifications();

        $this->registerManualElements($registry, $logger);

        $registry->enableNotifications();

        $server = new Server($configuration, $registry, $protocol, $sessionManager);

        return $server;
    }

    /**
     * Helper to perform the actual registration based on stored data.
     * Moved into the builder.
     */
    private function registerManualElements(Registry $registry, LoggerInterface $logger): void
    {
        if (empty($this->manualTools) && empty($this->manualResources) && empty($this->manualResourceTemplates) && empty($this->manualPrompts)) {
            return;
        }

        $errorCount = 0;
        $docBlockParser = new Utils\DocBlockParser($logger);
        $schemaGenerator = new Utils\SchemaGenerator($docBlockParser);

        // Register Tools
        foreach ($this->manualTools as $data) {
            try {
                $reflectionMethod = HandlerResolver::resolve($data['handler']);
                $className = $reflectionMethod->getDeclaringClass()->getName();
                $methodName = $reflectionMethod->getName();
                $docBlock = $docBlockParser->parseDocBlock($reflectionMethod->getDocComment() ?? null);

                $name = $data['name'] ?? ($reflectionMethod->getName() === '__invoke'
                    ? $reflectionMethod->getDeclaringClass()->getShortName()
                    : $reflectionMethod->getName());
                $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                $inputSchema = $schemaGenerator->fromMethodParameters($reflectionMethod);

                $tool = Tool::make($name, $inputSchema, $description, $data['annotations']);
                $registry->registerTool($tool, $className, $methodName, true);

                $logger->debug("Registered manual tool {$name} from handler {$className}::{$methodName}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual tool', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
            }
        }

        // Register Resources
        foreach ($this->manualResources as $data) {
            try {
                $reflectionMethod = HandlerResolver::resolve($data['handler']);
                $className = $reflectionMethod->getDeclaringClass()->getName();
                $methodName = $reflectionMethod->getName();
                $docBlock = $docBlockParser->parseDocBlock($reflectionMethod->getDocComment() ?? null);

                $uri = $data['uri'];
                $name = $data['name'] ?? ($methodName === '__invoke' ? $reflectionMethod->getDeclaringClass()->getShortName() : $methodName);
                $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                $mimeType = $data['mimeType'];
                $size = $data['size'];
                $annotations = $data['annotations'];

                $resource = Resource::make($uri, $name, $description, $mimeType, $annotations, $size);
                $registry->registerResource($resource, $className, $methodName, true);

                $logger->debug("Registered manual resource {$name} from handler {$className}::{$methodName}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual resource', ['handler' => $data['handler'], 'uri' => $data['uri'], 'exception' => $e]);
            }
        }

        // Register Templates
        foreach ($this->manualResourceTemplates as $data) {
            try {
                $reflectionMethod = HandlerResolver::resolve($data['handler']);
                $className = $reflectionMethod->getDeclaringClass()->getName();
                $methodName = $reflectionMethod->getName();
                $docBlock = $docBlockParser->parseDocBlock($reflectionMethod->getDocComment() ?? null);

                $uriTemplate = $data['uriTemplate'];
                $name = $data['name'] ?? ($methodName === '__invoke' ? $reflectionMethod->getDeclaringClass()->getShortName() : $methodName);
                $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                $mimeType = $data['mimeType'];
                $annotations = $data['annotations'];

                $template = ResourceTemplate::make($uriTemplate, $name, $description, $mimeType, $annotations);
                $completionProviders = $this->getCompletionProviders($reflectionMethod);
                $registry->registerResourceTemplate($template, $className, $methodName, true, $completionProviders);

                $logger->debug("Registered manual template {$name} from handler {$className}::{$methodName}");
            } catch (Throwable $e) {
                $errorCount++;
                $logger->error('Failed to register manual template', ['handler' => $data['handler'], 'uriTemplate' => $data['uriTemplate'], 'exception' => $e]);
            }
        }

        // Register Prompts
        foreach ($this->manualPrompts as $data) {
            try {
                $reflectionMethod = HandlerResolver::resolve($data['handler']);
                $className = $reflectionMethod->getDeclaringClass()->getName();
                $methodName = $reflectionMethod->getName();
                $docBlock = $docBlockParser->parseDocBlock($reflectionMethod->getDocComment() ?? null);

                $name = $data['name'] ?? ($methodName === '__invoke' ? $reflectionMethod->getDeclaringClass()->getShortName() : $methodName);
                $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;

                $arguments = [];
                $paramTags = $docBlockParser->getParamTags($docBlock);
                foreach ($reflectionMethod->getParameters() as $param) {
                    $reflectionType = $param->getType();

                    // Basic DI check (heuristic)
                    if ($reflectionType instanceof \ReflectionNamedType && ! $reflectionType->isBuiltin()) {
                        continue;
                    }

                    $paramTag = $paramTags['$' . $param->getName()] ?? null;
                    $arguments[] = PromptArgument::make(
                        name: $param->getName(),
                        description: $paramTag ? trim((string) $paramTag->getDescription()) : null,
                        required: ! $param->isOptional() && ! $param->isDefaultValueAvailable()
                    );
                }

                $prompt = Prompt::make($name, $description, $arguments);
                $completionProviders = $this->getCompletionProviders($reflectionMethod);
                $registry->registerPrompt($prompt, $className, $methodName, true, $completionProviders);

                $logger->debug("Registered manual prompt {$name} from handler {$className}::{$methodName}");
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

    private function getCompletionProviders(\ReflectionMethod $reflectionMethod): array
    {
        $completionProviders = [];
        foreach ($reflectionMethod->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }

            $completionAttributes = $param->getAttributes(CompletionProvider::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($completionAttributes)) {
                $attributeInstance = $completionAttributes[0]->newInstance();
                $completionProviders[$param->getName()] = $attributeInstance->providerClass;
            }
        }

        return $completionProviders;
    }
}
