<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use InvalidArgumentException;
use LogicException;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Defaults\FileCache;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Support\Discoverer;
use PhpMcp\Server\Support\DocBlockParser;
use PhpMcp\Server\Support\SchemaGenerator;
use PhpMcp\Server\Transports\StdioTransportHandler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Main MCP Server class providing a fluent interface for configuration and running.
 */
class Server
{
    private ?LoggerInterface $logger = null;

    private ?ContainerInterface $container = null;

    private ?Registry $registry = null;

    private string $basePath;

    private array $scanDirs;

    private array $excludeDirs;

    public function __construct()
    {
        $container = new BasicContainer;

        $config = new ArrayConfigurationRepository($this->getDefaultConfigValues());
        $logger = new NullLogger;
        $cache = new FileCache(__DIR__.'/../cache/mcp_cache');

        $container->set(ConfigurationRepositoryInterface::class, $config);
        $container->set(LoggerInterface::class, $logger);
        $container->set(CacheInterface::class, $cache);

        $this->basePath = realpath(__DIR__.'/..') ?: __DIR__.'/..';
        $this->scanDirs = ['.', 'src/MCP'];
        $this->excludeDirs = ['vendor', 'tests', 'test', 'samples', 'docs', 'storage', 'cache', 'node_modules'];
        $this->container = $container;
    }

    /**
     * Static factory method to create a new Server instance.
     */
    public static function make(): self
    {
        $instance = new self;

        return $instance;
    }

    private function getDefaultConfigValues(): array
    {
        return [
            'mcp' => [
                'server' => ['name' => 'PHP MCP Server', 'version' => '1.0.0'],
                'protocol_versions' => ['2024-11-05'],
                'pagination_limit' => 50,
                'capabilities' => [
                    'tools' => ['enabled' => true, 'listChanged' => true],
                    'resources' => ['enabled' => true, 'subscribe' => true, 'listChanged' => true],
                    'prompts' => ['enabled' => true, 'listChanged' => true],
                    'logging' => ['enabled' => false],
                ],
                'cache' => ['key' => 'mcp.elements.cache', 'ttl' => 3600, 'prefix' => 'mcp_state_'],
                'runtime' => ['log_level' => 'info'],
            ],
        ];
    }

    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        if ($this->container instanceof BasicContainer) {
            $this->container->set(LoggerInterface::class, $logger);
        }

        return $this;
    }

    public function withCache(CacheInterface $cache): self
    {
        if ($this->container instanceof BasicContainer) {
            $this->container->set(CacheInterface::class, $cache);
        }

        return $this;
    }

    public function withConfig(ConfigurationRepositoryInterface $config): self
    {
        if ($this->container instanceof BasicContainer) {
            $this->container->set(ConfigurationRepositoryInterface::class, $config);
        }

        return $this;
    }

    public function withBasePath(string $path): self
    {
        if (! is_dir($path)) {
            throw new InvalidArgumentException("Base path is not a valid directory: {$path}");
        }

        $this->basePath = realpath($path) ?: $path;

        return $this;
    }

    /**
     * Explicitly set the directories to scan relative to the base path.
     *
     * @param  string[]  $dirs  Array of relative directory paths (e.g., ['.', 'src/MCP']).
     * @return $this
     */
    public function withScanDirectories(array $dirs): self
    {
        $this->scanDirs = $dirs;

        return $this;
    }

    /**
     * Explicitly set the directories to exclude from the scan, relative to the base path.
     *
     * @param  string[]  $dirs  Array of relative directory paths (e.g., ['vendor', 'tests', 'test', 'samples', 'docs', 'storage', 'cache', 'node_modules']).
     * @return $this
     */
    public function withExcludeDirectories(array $dirs): self
    {
        $this->excludeDirs = array_merge($this->excludeDirs, $dirs);

        return $this;
    }

    /**
     * Manually register a tool with the server.
     *
     * @param  array<string, string>|class-string  $handler  The handler to register, containing a class name and method name.
     * @param  string|null  $name  The name of the tool.
     * @param  string|null  $description  The description of the tool.
     * @return $this
     */
    public function withTool(array|string $handler, ?string $name = null, ?string $description = null): self
    {
        $reflectionMethod = $this->validateAndGetReflectionMethod($handler);
        $className = $reflectionMethod->getDeclaringClass()->getName();
        $methodName = $reflectionMethod->getName();
        $isInvokable = $methodName === '__invoke';

        $docBlockParser = new DocBlockParser($this->container);
        $schemaGenerator = new SchemaGenerator($docBlockParser);

        $name = $name ?? ($isInvokable ? (new ReflectionClass($className))->getShortName() : $methodName);
        $definition = ToolDefinition::fromReflection($reflectionMethod, $name, $description, $docBlockParser, $schemaGenerator);

        $registry = $this->getRegistry();
        $registry->registerTool($definition);

        $this->logger->debug('MCP: Manually registered tool.', ['name' => $definition->getName(), 'handler' => "{$className}::{$methodName}"]);

        return $this;
    }

    /**
     * Manually register a resource with the server.
     *
     * @param  array<string, string>|class-string  $handler  The handler to register, containing a class name and method name.
     * @param  string  $uri  The URI of the resource.
     * @param  string|null  $name  The name of the resource.
     * @param  string|null  $description  The description of the resource.
     * @param  string|null  $mimeType  The MIME type of the resource.
     * @param  int|null  $size  The size of the resource.
     * @param  array<string, mixed>|null  $annotations  The annotations of the resource.
     */
    public function withResource(array|string $handler, string $uri, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?int $size = null, ?array $annotations = []): self
    {
        $reflectionMethod = $this->validateAndGetReflectionMethod($handler);
        $className = $reflectionMethod->getDeclaringClass()->getName();
        $methodName = $reflectionMethod->getName();
        $isInvokable = $methodName === '__invoke';

        $docBlockParser = new DocBlockParser($this->container);

        $name = $name ?? ($isInvokable ? (new ReflectionClass($className))->getShortName() : $methodName);
        $definition = ResourceDefinition::fromReflection($reflectionMethod, $name, $description, $uri, $mimeType, $size, $annotations, $docBlockParser);

        $registry = $this->getRegistry();
        $registry->registerResource($definition);

        $this->logger->debug('MCP: Manually registered resource.', ['name' => $definition->getName(), 'handler' => "{$className}::{$methodName}"]);

        return $this;
    }

    /**
     * Manually register a prompt with the server.
     *
     * @param  array<string, string>|class-string  $handler  The handler to register, containing a class name and method name.
     * @param  string|null  $name  The name of the prompt.
     * @param  string|null  $description  The description of the prompt.
     */
    public function withPrompt(array|string $handler, ?string $name = null, ?string $description = null): self
    {
        $reflectionMethod = $this->validateAndGetReflectionMethod($handler);
        $className = $reflectionMethod->getDeclaringClass()->getName();
        $methodName = $reflectionMethod->getName();
        $isInvokable = $methodName === '__invoke';

        $docBlockParser = new DocBlockParser($this->container);
        $name = $name ?? ($isInvokable ? (new ReflectionClass($className))->getShortName() : $methodName);
        $definition = PromptDefinition::fromReflection($reflectionMethod, $name, $description, $docBlockParser);

        $registry = $this->getRegistry();
        $registry->registerPrompt($definition);

        $this->logger->debug('MCP: Manually registered prompt.', ['name' => $definition->getName(), 'handler' => "{$className}::{$methodName}"]);

        return $this;
    }

    /**
     * Manually register a resource template with the server.
     *
     * @param  array<string, string>|class-string  $handler  The handler to register, containing a class name and method name.
     * @param  string|null  $name  The name of the resource template.
     * @param  string|null  $description  The description of the resource template.
     * @param  string|null  $uriTemplate  The URI template of the resource template.
     * @param  string|null  $mimeType  The MIME type of the resource template.
     * @param  array<string, mixed>|null  $annotations  The annotations of the resource template.
     */
    public function withResourceTemplate(array|string $handler, ?string $name = null, ?string $description = null, ?string $uriTemplate = null, ?string $mimeType = null, ?array $annotations = []): self
    {
        $reflectionMethod = $this->validateAndGetReflectionMethod($handler);
        $className = $reflectionMethod->getDeclaringClass()->getName();
        $methodName = $reflectionMethod->getName();
        $isInvokable = $methodName === '__invoke';

        $docBlockParser = new DocBlockParser($this->container);
        $name = $name ?? ($isInvokable ? (new ReflectionClass($className))->getShortName() : $methodName);
        $definition = ResourceTemplateDefinition::fromReflection($reflectionMethod, $name, $description, $uriTemplate, $mimeType, $annotations, $docBlockParser);

        $registry = $this->getRegistry();
        $registry->registerResourceTemplate($definition);

        $this->logger->debug('MCP: Manually registered resource template.', ['name' => $definition->getName(), 'handler' => "{$className}::{$methodName}"]);

        return $this;
    }

    /**
     * Validates a handler and returns its ReflectionMethod.
     *
     * @param  array|string  $handler  The handler to validate
     * @return ReflectionMethod The reflection method for the handler
     *
     * @throws InvalidArgumentException If the handler is invalid
     */
    private function validateAndGetReflectionMethod(array|string $handler): ReflectionMethod
    {
        $className = null;
        $methodName = null;

        if (is_array($handler)) {
            if (count($handler) !== 2 || ! is_string($handler[0]) || ! is_string($handler[1])) {
                throw new InvalidArgumentException('Invalid handler format. Expected [ClassName::class, \'methodName\'].');
            }
            [$className, $methodName] = $handler;
            if (! class_exists($className)) {
                throw new InvalidArgumentException("Class '{$className}' not found for array handler.");
            }
            if (! method_exists($className, $methodName)) {
                throw new InvalidArgumentException("Method '{$methodName}' not found in class '{$className}' for array handler.");
            }
        } elseif (is_string($handler) && class_exists($handler)) {
            $className = $handler;
            $methodName = '__invoke';
            if (! method_exists($className, $methodName)) {
                throw new InvalidArgumentException("Invokable class '{$className}' must have a public '__invoke' method.");
            }
        } else {
            throw new InvalidArgumentException('Invalid handler format. Expected [ClassName::class, \'methodName\'] or InvokableClassName::class string.');
        }

        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);

            if ($reflectionMethod->isStatic()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be static.");
            }
            if (! $reflectionMethod->isPublic()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' must be public.");
            }
            if ($reflectionMethod->isAbstract()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be abstract.");
            }
            if ($reflectionMethod->isConstructor() || $reflectionMethod->isDestructor()) {
                throw new InvalidArgumentException("Handler method '{$className}::{$methodName}' cannot be a constructor or destructor.");
            }

            return $reflectionMethod;

        } catch (\ReflectionException $e) {
            throw new InvalidArgumentException("Reflection error for handler '{$className}::{$methodName}': {$e->getMessage()}", 0, $e);
        }
    }

    // --- Core Actions --- //

    public function discover(bool $cache = true): self
    {
        $registry = $this->getRegistry();

        $discoverer = new Discoverer($this->container, $registry);

        $discoverer->discover($this->basePath, $this->scanDirs, $this->excludeDirs);

        if ($cache) {
            $registry->saveElementsToCache();
        }

        return $this;
    }

    public function run(?string $transport = null): int
    {
        if ($transport === null) {
            $sapi = php_sapi_name();
            $transport = (str_starts_with($sapi, 'cli') || str_starts_with($sapi, 'phpdbg')) ? 'stdio' : 'http';
            $this->logger->info('Auto-detected transport', ['sapi' => $sapi, 'transport' => $transport]);
        }

        $handler = match (strtolower($transport)) {
            'stdio' => new StdioTransportHandler($this),
            'reactphp' => throw new LogicException('MCP: reactphp transport cannot be run directly via Server::run(). Integrate ReactPhpHttpTransportHandler into your ReactPHP server.'),
            'http' => throw new LogicException("Cannot run HTTP transport directly via Server::run(). Instantiate \PhpMcp\Server\Transports\HttpTransportHandler and integrate it into your HTTP server/framework."),
            default => throw new LogicException("Unsupported transport: {$transport}"),
        };

        return $handler->start();
    }

    public function getRegistry(): Registry
    {
        if (is_null($this->registry)) {
            $this->registry = new Registry($this->container);
        }

        return $this->registry;
    }

    public function getProcessor(): Processor
    {
        $registry = $this->getRegistry();

        return new Processor($this->container, $registry);
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}
