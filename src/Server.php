<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use LogicException;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Exception\DiscoveryException;
use PhpMcp\Server\Support\Discoverer;
use Throwable;

/**
 * Core MCP Server instance.
 *
 * Holds the configured MCP logic (Registry, Processor, State, Configuration)
 * but is transport-agnostic. It relies on a ServerTransportInterface implementation,
 * provided via the listen() method, to handle network communication.
 *
 * Instances should be created via the ServerBuilder.
 */
class Server
{
    protected ?ProtocolHandler $protocolHandler = null;

    protected bool $discoveryRan = false;

    protected bool $isListening = false;

    /**
     *  @internal Use ServerBuilder::make()->...->build().
     *
     * @param  Configuration  $configuration  Core configuration and dependencies.
     * @param  Registry  $registry  Holds registered MCP element definitions.
     * @param  Processor  $processor  Handles processing of MCP requests.
     * @param  ClientStateManager  $clientStateManager  Manages client runtime state.
     * @param  array|null  $discoveryConfig  Configuration for attribute discovery, or null if disabled/not set.
     */
    public function __construct(
        protected readonly Configuration $configuration,
        protected readonly Registry $registry,
        protected readonly Processor $processor,
        protected readonly ClientStateManager $clientStateManager,
    ) {
    }

    public static function make(): ServerBuilder
    {
        return new ServerBuilder();
    }

    /**
     * Runs the attribute discovery process based on the configuration
     * provided during build time. Caches results if cache is available.
     * Can be called explicitly, but is also called by ServerBuilder::build()
     * if discovery paths are configured.
     *
     * @param  bool  $force  Re-run discovery even if already run.
     * @param  bool  $useCache  Attempt to load from/save to cache. Defaults to true if cache is available.
     *
     * @throws DiscoveryException If discovery process encounters errors.
     * @throws ConfigurationException If discovery paths were not configured.
     */
    public function discover(
        string $basePath,
        array $scanDirs = ['.', 'src'],
        array $excludeDirs = [],
        bool $force = false,
        bool $saveToCache = true
    ): void {
        $realBasePath = realpath($basePath);
        if ($realBasePath === false || ! is_dir($realBasePath)) {
            throw new \InvalidArgumentException("Invalid discovery base path provided to discover(): {$basePath}");
        }

        $excludeDirs = array_merge($excludeDirs, ['vendor', 'tests', 'test', 'storage', 'cache', 'samples', 'docs', 'node_modules']);

        if ($this->discoveryRan && ! $force) {
            $this->configuration->logger->debug('Discovery skipped: Already run or loaded from cache.');

            return;
        }

        $cacheAvailable = $this->configuration->cache !== null;
        $shouldSaveCache = $saveToCache && $cacheAvailable;

        $this->configuration->logger->info('Starting MCP element discovery...', [
            'basePath' => $realBasePath, 'force' => $force, 'saveToCache' => $shouldSaveCache,
        ]);

        $this->registry->clearDiscoveredElements($shouldSaveCache);

        try {
            $discoverer = new Discoverer($this->registry, $this->configuration->logger);

            $discoverer->discover($realBasePath, $scanDirs, $excludeDirs);

            $this->discoveryRan = true;
            $this->configuration->logger->info('Element discovery process finished.');

            if ($shouldSaveCache) {
                $this->registry->saveDiscoveredElementsToCache();
            }
        } catch (Throwable $e) {
            $this->discoveryRan = false;
            $this->configuration->logger->critical('MCP element discovery failed.', ['exception' => $e]);
            throw new DiscoveryException("Element discovery failed: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Binds the server's MCP logic to the provided transport and starts the transport's listener,
     * then runs the event loop, making this a BLOCKING call suitable for standalone servers.
     *
     * For framework integration where the loop is managed externally, use `getProtocolHandler()`
     * and bind it to your framework's transport mechanism manually.
     *
     * @param  ServerTransportInterface  $transport  The transport to listen with.
     *
     * @throws LogicException If called after already listening.
     * @throws Throwable If transport->listen() fails immediately.
     */
    public function listen(ServerTransportInterface $transport): void
    {
        if ($this->isListening) {
            throw new LogicException('Server is already listening via a transport.');
        }

        $this->warnIfNoElements();

        if ($transport instanceof LoggerAwareInterface) {
            $transport->setLogger($this->configuration->logger);
        }
        if ($transport instanceof LoopAwareInterface) {
            $transport->setLoop($this->configuration->loop);
        }

        $protocolHandler = $this->getProtocolHandler();

        $closeHandlerCallback = function (?string $reason = null) use ($protocolHandler) {
            $this->isListening = false;
            $this->configuration->logger->info('Transport closed.', ['reason' => $reason ?? 'N/A']);
            $protocolHandler->unbindTransport();
            $this->configuration->loop->stop();
        };

        $transport->once('close', $closeHandlerCallback);

        $protocolHandler->bindTransport($transport);

        try {
            $transport->listen();

            $this->isListening = true;

            $this->configuration->loop->run(); // BLOCKING

        } catch (Throwable $e) {
            $this->configuration->logger->critical('Failed to start listening or event loop crashed.', ['exception' => $e]);
            if ($this->isListening) {
                $protocolHandler->unbindTransport();
                $transport->removeListener('close', $closeHandlerCallback); // Remove listener
                $transport->close();
            }
            $this->isListening = false;
            throw $e;
        } finally {
            if ($this->isListening) {
                $protocolHandler->unbindTransport();
                $transport->removeListener('close', $closeHandlerCallback);
                $transport->close();
            }
            $this->isListening = false;
            $this->configuration->logger->info("Server '{$this->configuration->serverName}' listener shut down.");
        }
    }

    protected function warnIfNoElements(): void
    {
        if (! $this->registry->hasElements() && ! $this->discoveryRan) {
            $this->configuration->logger->warning(
                'Starting listener, but no MCP elements are registered and discovery has not been run. '.
                'Call $server->discover(...) at least once to find and cache elements before listen().'
            );
        } elseif (! $this->registry->hasElements() && $this->discoveryRan) {
            $this->configuration->logger->warning(
                'Starting listener, but no MCP elements were found after discovery/cache load.'
            );
        }
    }

    /**
     * Gets the ProtocolHandler instance associated with this server.
     *
     * Useful for framework integrations where the event loop and transport
     * communication are managed externally.
     */
    public function getProtocolHandler(): ProtocolHandler
    {
        if ($this->protocolHandler === null) {
            $this->protocolHandler = new ProtocolHandler(
                $this->processor,
                $this->clientStateManager,
                $this->configuration->logger,
                $this->configuration->loop
            );
        }

        return $this->protocolHandler;
    }

    // --- Getters for Core Components ---

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    public function getProcessor(): Processor
    {
        return $this->processor;
    }

    public function getClientStateManager(): ClientStateManager
    {
        return $this->clientStateManager;
    }
}
