<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use LogicException;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Defaults\ArrayCache;
use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Defaults\StreamLogger;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Support\Discoverer;
use PhpMcp\Server\Transports\StdioTransportHandler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;

/**
 * Main MCP Server class providing a fluent interface for configuration and running.
 */
class Server
{
    private ?ConfigurationRepositoryInterface $config = null;

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $cache = null;

    private ?ContainerInterface $container = null;

    private ?Registry $registry = null;

    private ?TransportState $transportState = null;

    private ?Processor $processor = null;

    private ?Discoverer $discoverer = null;

    private ?string $basePath = null;

    private ?array $scanDirs = null;

    public function __construct() {}

    /**
     * Static factory method to create a new Server instance.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Initializes core dependencies and dependent services if not already done.
     */
    private function initialize(): void
    {
        // 1. Apply Defaults if Dependencies are Null
        if ($this->logger === null) {
            $this->logger = new StreamLogger(STDERR, LogLevel::INFO); // Log to STDERR by default
        }
        if ($this->cache === null) {
            $this->cache = new ArrayCache;
        }
        if ($this->container === null) {
            $this->container = new BasicContainer;
        }

        // Initialize or update config
        if ($this->config === null) {
            // --- Use explicit paths if set, otherwise use defaults ---
            $defaultBasePath = $this->basePath ?? getcwd();
            $defaultScanDirs = $this->scanDirs ?? ['.'];

            $defaultConfigValues = [
                'mcp' => [
                    'server' => ['name' => 'PHP MCP Server', 'version' => '1.0.0'],
                    'protocol_versions' => ['2024-11-05'],
                    'pagination_limit' => 50,
                    'capabilities' => [
                        'tools' => ['enabled' => true, 'listChanged' => true],
                        'resources' => ['enabled' => true, 'subscribe' => true, 'listChanged' => true],
                        'prompts' => ['enabled' => true, 'listChanged' => true],
                        'logging' => ['enabled' => true],
                    ],
                    'cache' => ['key' => 'mcp.elements.cache', 'ttl' => 3600, 'prefix' => 'mcp_state_'],
                    'discovery' => ['base_path' => $defaultBasePath, 'directories' => $defaultScanDirs],
                    'runtime' => ['log_level' => 'info'],
                ],
            ];
            $this->config = new ArrayConfigurationRepository($defaultConfigValues);
        } else {
            $basePath = $this->basePath ?? $this->config->get('mcp.discovery.base_path', getcwd());
            $scanDirs = $this->scanDirs ?? $this->config->get('mcp.discovery.directories', ['.']);

            $this->config->set('mcp.discovery.base_path', $basePath);
            $this->config->set('mcp.discovery.directories', $scanDirs);
            $this->config->set('mcp.cache.prefix', $this->config->get('mcp.cache.prefix', 'mcp_'));
        }

        // 2. Instantiate Dependent Services
        $this->transportState = new TransportState(
            $this->cache,
            $this->logger,
            $this->config->get('mcp.cache.prefix'),
            $this->config->get('mcp.cache.ttl')
        );

        $this->registry ??= new Registry(
            $this->cache,
            $this->logger,
            $this->transportState,
            $this->config->get('mcp.cache.prefix')
        );

        $this->processor ??= new Processor(
            $this->container, // Processor requires a container
            $this->config,
            $this->registry,
            $this->transportState,
            $this->logger
        );

        $this->discoverer ??= new Discoverer($this->registry, $this->logger);

        // 3. Add core services to BasicContainer if it's being used
        if ($this->container instanceof BasicContainer) {
            $this->container->set(LoggerInterface::class, $this->logger);
            $this->container->set(CacheInterface::class, $this->cache);
            $this->container->set(ConfigurationRepositoryInterface::class, $this->config);
            $this->container->set(Registry::class, $this->registry);
        }
    }

    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withCache(CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    public function withConfig(ConfigurationRepositoryInterface $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function withBasePath(string $path): self
    {
        $this->basePath = $path;
        if ($this->config) {
            $this->config->set('mcp.discovery.base_path', $path);
        }

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
        if ($this->config) {
            $this->config->set('mcp.discovery.directories', $dirs);
        }

        return $this;
    }

    // --- Core Actions --- //

    public function discover(bool $clearCacheFirst = true): self
    {
        $this->initialize(); // Ensures config is correctly set using explicit paths if provided

        if ($clearCacheFirst) {
            $this->registry->clearCache();
        }

        // Now read the finalized paths from config
        $basePath = $this->config->get('mcp.discovery.base_path');
        $scanDirectories = $this->config->get('mcp.discovery.directories');

        $this->discoverer->discover($basePath, $scanDirectories);
        $this->registry->cacheElements();

        return $this;
    }

    public function run(?string $transport = null): int
    {
        $this->initialize();

        $this->registry->loadElements();

        if ($transport === null) {
            $sapi = php_sapi_name();
            $transport = (str_starts_with($sapi, 'cli') || str_starts_with($sapi, 'phpdbg')) ? 'stdio' : 'http';
            $this->logger->info('Auto-detected transport', ['sapi' => $sapi, 'transport' => $transport]);
        }

        $handler = match (strtolower($transport)) {
            'stdio' => new StdioTransportHandler($this->processor, $this->transportState, $this->logger),
            'http' => throw new LogicException("Cannot run HTTP transport directly via Server::run(). Instantiate \PhpMcp\Server\Transports\HttpTransportHandler and integrate it into your HTTP server/framework."),
            default => throw new LogicException("Unsupported transport: {$transport}"),
        };

        return $handler->start();
    }

    // --- Component Getters (Ensure initialization) --- //

    public function getProcessor(): Processor
    {
        $this->initialize();

        return $this->processor;
    }

    public function getRegistry(): Registry
    {
        $this->initialize();

        return $this->registry;
    }

    public function getStateManager(): TransportState
    {
        $this->initialize();

        return $this->transportState;
    }

    public function getConfig(): ConfigurationRepositoryInterface
    {
        $this->initialize();

        return $this->config;
    }

    public function getLogger(): LoggerInterface
    {
        $this->initialize();

        return $this->logger;
    }

    public function getCache(): CacheInterface
    {
        $this->initialize();

        return $this->cache;
    }

    public function getContainer(): ContainerInterface
    {
        $this->initialize();

        return $this->container;
    }
}
