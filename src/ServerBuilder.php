<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use Closure;
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
use PhpMcp\Server\Defaults\EnumCompletionProvider;
use PhpMcp\Server\Defaults\ListCompletionProvider;
use PhpMcp\Server\Exception\ConfigurationException;

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

    private ?string $sessionDriver = null;

    private ?int $sessionTtl = 3600;

    private ?int $paginationLimit = 50;

    private ?string $instructions = null;

    /** @var array<
     *     array{handler: array|string|Closure,
     *     name: string|null,
     *     description: string|null,
     *     annotations: ToolAnnotations|null}
     * > */
    private array $manualTools = [];

    /** @var array<
     *     array{handler: array|string|Closure,
     *     uri: string,
     *     name: string|null,
     *     description: string|null,
     *     mimeType: string|null,
     *     size: int|null,
     *     annotations: Annotations|null}
     * > */
    private array $manualResources = [];

    /** @var array<
     *     array{handler: array|string|Closure,
     *     uriTemplate: string,
     *     name: string|null,
     *     description: string|null,
     *     mimeType: string|null,
     *     annotations: Annotations|null}
     * > */
    private array $manualResourceTemplates = [];

    /** @var array<
     *     array{handler: array|string|Closure,
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
     * Configures the instructions describing how to use the server and its features. 
     * 
     * This can be used by clients to improve the LLM's understanding of available tools, resources,
     * etc. It can be thought of like a "hint" to the model. For example, this information MAY 
     * be added to the system prompt.
     */
    public function withInstructions(?string $instructions): self
    {
        $this->instructions = $instructions;

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
     * Provides a PSR-16 cache instance used for all internal caching.
     */
    public function withCache(CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Configures session handling with a specific driver.
     * 
     * @param 'array' | 'cache' $driver The session driver: 'array' for in-memory sessions, 'cache' for cache-backed sessions
     * @param int $ttl Session time-to-live in seconds. Defaults to 3600.
     */
    public function withSession(string $driver, int $ttl = 3600): self
    {
        if (!in_array($driver, ['array', 'cache'], true)) {
            throw new \InvalidArgumentException(
                "Unsupported session driver '{$driver}'. Only 'array' and 'cache' drivers are supported. " .
                    "For custom session handling, use withSessionHandler() instead."
            );
        }

        $this->sessionDriver = $driver;
        $this->sessionTtl = $ttl;

        return $this;
    }

    /**
     * Provides a custom session handler.
     */
    public function withSessionHandler(SessionHandlerInterface $sessionHandler, int $sessionTtl = 3600): self
    {
        $this->sessionHandler = $sessionHandler;
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
    public function withTool(callable|array|string $handler, ?string $name = null, ?string $description = null, ?ToolAnnotations $annotations = null, ?array $inputSchema = null): self
    {
        $this->manualTools[] = compact('handler', 'name', 'description', 'annotations', 'inputSchema');

        return $this;
    }

    /**
     * Manually registers a resource handler.
     */
    public function withResource(callable|array|string $handler, string $uri, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?int $size = null, ?Annotations $annotations = null): self
    {
        $this->manualResources[] = compact('handler', 'uri', 'name', 'description', 'mimeType', 'size', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource template handler.
     */
    public function withResourceTemplate(callable|array|string $handler, string $uriTemplate, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?Annotations $annotations = null): self
    {
        $this->manualResourceTemplates[] = compact('handler', 'uriTemplate', 'name', 'description', 'mimeType', 'annotations');

        return $this;
    }

    /**
     * Manually registers a prompt handler.
     */
    public function withPrompt(callable|array|string $handler, ?string $name = null, ?string $description = null): self
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
            paginationLimit: $this->paginationLimit ?? 50,
            instructions: $this->instructions,
        );

        $sessionHandler = $this->createSessionHandler();
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

        $docBlockParser = new Utils\DocBlockParser($logger);
        $schemaGenerator = new Utils\SchemaGenerator($docBlockParser);

        // Register Tools
        foreach ($this->manualTools as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_tool_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ($methodName === '__invoke' ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $inputSchema = $data['inputSchema'] ?? $schemaGenerator->generate($reflection);

                $tool = Tool::make($name, $inputSchema, $description, $data['annotations']);
                $registry->registerTool($tool, $data['handler'], true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual tool {$name} from handler {$handlerDesc}");
            } catch (Throwable $e) {
                $logger->error('Failed to register manual tool', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual tool '{$data['name']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Resources
        foreach ($this->manualResources as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_resource_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ($methodName === '__invoke' ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $uri = $data['uri'];
                $mimeType = $data['mimeType'];
                $size = $data['size'];
                $annotations = $data['annotations'];

                $resource = Resource::make($uri, $name, $description, $mimeType, $annotations, $size);
                $registry->registerResource($resource, $data['handler'], true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual resource {$name} from handler {$handlerDesc}");
            } catch (Throwable $e) {
                $logger->error('Failed to register manual resource', ['handler' => $data['handler'], 'uri' => $data['uri'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual resource '{$data['uri']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Templates
        foreach ($this->manualResourceTemplates as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_template_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ($methodName === '__invoke' ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $uriTemplate = $data['uriTemplate'];
                $mimeType = $data['mimeType'];
                $annotations = $data['annotations'];

                $template = ResourceTemplate::make($uriTemplate, $name, $description, $mimeType, $annotations);
                $completionProviders = $this->getCompletionProviders($reflection);
                $registry->registerResourceTemplate($template, $data['handler'], $completionProviders, true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual template {$name} from handler {$handlerDesc}");
            } catch (Throwable $e) {
                $logger->error('Failed to register manual template', ['handler' => $data['handler'], 'uriTemplate' => $data['uriTemplate'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual resource template '{$data['uriTemplate']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Prompts
        foreach ($this->manualPrompts as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_prompt_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ($methodName === '__invoke' ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $arguments = [];
                $paramTags = $reflection instanceof \ReflectionMethod ? $docBlockParser->getParamTags($docBlockParser->parseDocBlock($reflection->getDocComment() ?? null)) : [];
                foreach ($reflection->getParameters() as $param) {
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
                $completionProviders = $this->getCompletionProviders($reflection);
                $registry->registerPrompt($prompt, $data['handler'], $completionProviders, true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual prompt {$name} from handler {$handlerDesc}");
            } catch (Throwable $e) {
                $logger->error('Failed to register manual prompt', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual prompt '{$data['name']}': {$e->getMessage()}", 0, $e);
            }
        }

        $logger->debug('Manual element registration complete.');
    }

    /**
     * Creates the appropriate session handler based on configuration.
     * 
     * @throws ConfigurationException If cache driver is selected but no cache is provided
     */
    private function createSessionHandler(): SessionHandlerInterface
    {
        // If a custom session handler was provided, use it
        if ($this->sessionHandler !== null) {
            return $this->sessionHandler;
        }

        // If no session driver was specified, default to array
        if ($this->sessionDriver === null) {
            return new ArraySessionHandler($this->sessionTtl ?? 3600);
        }

        // Create handler based on driver
        return match ($this->sessionDriver) {
            'array' => new ArraySessionHandler($this->sessionTtl ?? 3600),
            'cache' => $this->createCacheSessionHandler(),
            default => throw new ConfigurationException("Unsupported session driver: {$this->sessionDriver}")
        };
    }

    /**
     * Creates a cache-based session handler.
     * 
     * @throws ConfigurationException If no cache is configured
     */
    private function createCacheSessionHandler(): CacheSessionHandler
    {
        if ($this->cache === null) {
            throw new ConfigurationException(
                "Cache session driver requires a cache instance. Please configure a cache using withCache() before using withSession('cache')."
            );
        }

        return new CacheSessionHandler($this->cache, $this->sessionTtl ?? 3600);
    }

    private function getCompletionProviders(\ReflectionMethod|\ReflectionFunction $reflection): array
    {
        $completionProviders = [];
        foreach ($reflection->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }

            $completionAttributes = $param->getAttributes(CompletionProvider::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($completionAttributes)) {
                $attributeInstance = $completionAttributes[0]->newInstance();

                if ($attributeInstance->provider) {
                    $completionProviders[$param->getName()] = $attributeInstance->provider;
                } elseif ($attributeInstance->providerClass) {
                    $completionProviders[$param->getName()] = $attributeInstance->providerClass;
                } elseif ($attributeInstance->values) {
                    $completionProviders[$param->getName()] = new ListCompletionProvider($attributeInstance->values);
                } elseif ($attributeInstance->enum) {
                    $completionProviders[$param->getName()] = new EnumCompletionProvider($attributeInstance->enum);
                }
            }
        }

        return $completionProviders;
    }
}
