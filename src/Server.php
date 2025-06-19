<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use LogicException;
use PhpMcp\Server\Contracts\LoggerAwareInterface;
use PhpMcp\Server\Contracts\LoopAwareInterface;
use PhpMcp\Server\Contracts\ServerTransportInterface;
use PhpMcp\Server\Exception\ConfigurationException;
use PhpMcp\Server\Exception\DiscoveryException;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Utils\Discoverer;
use Throwable;

/**
 * Core MCP Server instance.
 *
 * Holds the configured MCP logic (Configuration, Registry, Protocol)
 * but is transport-agnostic. It relies on a ServerTransportInterface implementation,
 * provided via the listen() method, to handle network communication.
 *
 * Instances should be created via the ServerBuilder.
 */
class Server
{
    protected bool $discoveryRan = false;

    protected bool $isListening = false;

    /**
     *  @internal Use ServerBuilder::make()->...->build().
     *
     * @param  Configuration  $configuration  Core configuration and dependencies.
     * @param  Registry  $registry  Holds registered MCP element definitions.
     * @param  Protocol  $protocol  Handles MCP requests and responses.
     */
    public function __construct(
        protected readonly Configuration $configuration,
        protected readonly Registry $registry,
        protected readonly Protocol $protocol,
        protected readonly SessionManager $sessionManager,
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
        bool $saveToCache = true,
        ?Discoverer $discoverer = null
    ): void {
        $realBasePath = realpath($basePath);
        if ($realBasePath === false || ! is_dir($realBasePath)) {
            throw new \InvalidArgumentException("Invalid discovery base path provided to discover(): {$basePath}");
        }

        $excludeDirs = array_merge($excludeDirs, ['vendor', 'tests', 'test', 'storage', 'cache', 'samples', 'docs', 'node_modules', '.git', '.svn']);

        if ($this->discoveryRan && ! $force) {
            $this->configuration->logger->debug('Discovery skipped: Already run or loaded from cache.');

            return;
        }

        $cacheAvailable = $this->configuration->cache !== null;
        $shouldSaveCache = $saveToCache && $cacheAvailable;

        $this->configuration->logger->info('Starting MCP element discovery...', [
            'basePath' => $realBasePath,
            'force' => $force,
            'saveToCache' => $shouldSaveCache,
        ]);

        $this->registry->clear();

        try {
            $discoverer ??= new Discoverer($this->registry, $this->configuration->logger);

            $discoverer->discover($realBasePath, $scanDirs, $excludeDirs);

            $this->discoveryRan = true;

            if ($shouldSaveCache) {
                $this->registry->save();
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
     * For framework integration where the loop is managed externally, use `getProtocol()`
     * and bind it to your framework's transport mechanism manually.
     *
     * @param  ServerTransportInterface  $transport  The transport to listen with.
     *
     * @throws LogicException If called after already listening.
     * @throws Throwable If transport->listen() fails immediately.
     */
    public function listen(ServerTransportInterface $transport, bool $runLoop = true): void
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

        $protocol = $this->getProtocol();

        $closeHandlerCallback = function (?string $reason = null) use ($protocol) {
            $this->isListening = false;
            $this->configuration->logger->info('Transport closed.', ['reason' => $reason ?? 'N/A']);
            $protocol->unbindTransport();
            $this->configuration->loop->stop();
        };

        $transport->once('close', $closeHandlerCallback);

        $protocol->bindTransport($transport);

        try {
            $transport->listen();

            $this->isListening = true;

            if ($runLoop) {
                $this->sessionManager->startGcTimer();

                $this->configuration->loop->run();

                $this->endListen($transport);
            }
        } catch (Throwable $e) {
            $this->configuration->logger->critical('Failed to start listening or event loop crashed.', ['exception' => $e->getMessage()]);
            $this->endListen($transport);
            throw $e;
        }
    }

    public function endListen(ServerTransportInterface $transport): void
    {
        $protocol = $this->getProtocol();

        $protocol->unbindTransport();

        $this->sessionManager->stopGcTimer();

        $transport->removeAllListeners('close');
        $transport->close();

        $this->isListening = false;
        $this->configuration->logger->info("Server '{$this->configuration->serverInfo->name}' listener shut down.");
    }

    /**
     * Warns if no MCP elements are registered and discovery has not been run.
     */
    protected function warnIfNoElements(): void
    {
        if (! $this->registry->hasElements() && ! $this->discoveryRan) {
            $this->configuration->logger->warning(
                'Starting listener, but no MCP elements are registered and discovery has not been run. ' .
                    'Call $server->discover(...) at least once to find and cache elements before listen().'
            );
        } elseif (! $this->registry->hasElements() && $this->discoveryRan) {
            $this->configuration->logger->warning(
                'Starting listener, but no MCP elements were found after discovery/cache load.'
            );
        }
    }

    /**
     * Gets the Configuration instance associated with this server.
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * Gets the Registry instance associated with this server.
     */
    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    /**
     * Gets the Protocol instance associated with this server.
     */
    public function getProtocol(): Protocol
    {
        return $this->protocol;
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }
}
