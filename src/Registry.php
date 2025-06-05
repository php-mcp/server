<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use ArrayObject;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Support\UriTemplateMatcher;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Throwable;

class Registry
{
    private const DISCOVERED_ELEMENTS_CACHE_KEY = 'mcp_server_discovered_elements';

    /** @var ArrayObject<string, ToolDefinition> */
    private ArrayObject $tools;

    /** @var ArrayObject<string, ResourceDefinition> */
    private ArrayObject $resources;

    /** @var ArrayObject<string, PromptDefinition> */
    private ArrayObject $prompts;

    /** @var ArrayObject<string, ResourceTemplateDefinition> */
    private ArrayObject $resourceTemplates;

    /** @var array<string, true> */
    private array $manualToolNames = [];

    /** @var array<string, true> */
    private array $manualResourceUris = [];

    /** @var array<string, true> */
    private array $manualPromptNames = [];

    /** @var array<string, true> */
    private array $manualTemplateUris = [];

    private bool $discoveredElementsLoaded = false;

    private bool $notificationsEnabled = true;


    public function __construct(
        protected LoggerInterface $logger,
        protected ?CacheInterface $cache = null,
        protected ?SessionManager $sessionManager = null
    ) {
        $this->initializeCollections();

        if ($this->cache) {
            $this->loadDiscoveredElementsFromCache();
        } else {
            $this->discoveredElementsLoaded = true;
            $this->logger->debug('No cache provided to registry, skipping initial cache load.');
        }
    }

    /**
     * Checks if discovery has been run OR elements loaded from cache.
     *
     * Note: Manual elements can exist even if this is false initially.
     */
    public function discoveryRanOrCached(): bool
    {
        return $this->discoveredElementsLoaded;
    }

    /** Checks if any elements (manual or discovered) are currently registered. */
    public function hasElements(): bool
    {
        return ! $this->tools->getArrayCopy() === false
            || ! $this->resources->getArrayCopy() === false
            || ! $this->prompts->getArrayCopy() === false
            || ! $this->resourceTemplates->getArrayCopy() === false;
    }

    private function initializeCollections(): void
    {
        $this->tools = new ArrayObject();
        $this->resources = new ArrayObject();
        $this->prompts = new ArrayObject();
        $this->resourceTemplates = new ArrayObject();

        $this->manualToolNames = [];
        $this->manualResourceUris = [];
        $this->manualPromptNames = [];
        $this->manualTemplateUris = [];
    }

    public function enableNotifications(): void
    {
        $this->notifyToolsChanged = function () {
            if ($this->sessionManager) {
                $notification = Notification::make('notifications/tools/list_changed');
                $framedMessage = json_encode($notification->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                if ($framedMessage !== false) {
                    $this->sessionManager->queueMessageForAll($framedMessage);
                }
            }
        };

        $this->notifyResourcesChanged = function () {
            if ($this->sessionManager) {
                $notification = Notification::make('notifications/resources/list_changed');
                $framedMessage = json_encode($notification->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                if ($framedMessage !== false) {
                    $this->sessionManager->queueMessageForAll($framedMessage);
                }
            }
        };

        $this->notifyPromptsChanged = function () {
            if ($this->sessionManager) {
                $notification = Notification::make('notifications/prompts/list_changed');
                $framedMessage = json_encode($notification->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                if ($framedMessage !== false) {
                    $this->sessionManager->queueMessageForAll($framedMessage);
                }
            }
        };
    }

    public function setToolsChangedNotifier(?callable $notifier): void
    {
        if (!$this->notificationsEnabled || !$this->clientStateManager) {
            return;
        }
        $notification = Notification::make('notifications/prompts/list_changed');

        $framedMessage = json_encode($notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if ($framedMessage === false || $framedMessage === "\n") {
            $this->logger->error('Failed to encode notification for queuing.', ['method' => $notification->method]);
            return;
        }
        $this->clientStateManager->queueMessageForAll($framedMessage);
    }

    public function notifyResourceUpdated(string $uri): void
    {
        if (!$this->notificationsEnabled || !$this->clientStateManager) {
            return;
        }

        $subscribers = $this->clientStateManager->getResourceSubscribers($uri);
        if (empty($subscribers)) {
            return;
        }
        $notification = Notification::make('notifications/resources/updated', ['uri' => $uri]);

        $framedMessage = json_encode($notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if ($framedMessage === false || $framedMessage === "\n") {
            $this->logger->error('Failed to encode resource/updated notification.', ['uri' => $uri]);
            return;
        }

        foreach ($subscribers as $clientId) {
            $this->clientStateManager->queueMessage($clientId, $framedMessage);
        }
    }

    /** @deprecated  */
    public function setToolsChangedNotifier(?callable $notifier): void {}

    /** @deprecated  */
    public function setResourcesChangedNotifier(?callable $notifier): void {}

    /** @deprecated  */
    public function setPromptsChangedNotifier(?callable $notifier): void {}

    public function registerTool(ToolDefinition $tool, bool $isManual = false): void
    {
        $toolName = $tool->getName();
        $exists = $this->tools->offsetExists($toolName);
        $wasManual = isset($this->manualToolNames[$toolName]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("MCP Registry: Ignoring discovered tool '{$toolName}' as it conflicts with a manually registered one.");

            return; // Manual registration takes precedence
        }

        if ($exists) {
            $this->logger->warning('MCP Registry: Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " tool '{$toolName}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->tools[$toolName] = $tool;

        if ($isManual) {
            $this->manualToolNames[$toolName] = true;
        } elseif ($wasManual) {
            unset($this->manualToolNames[$toolName]);
        }

        if (! $exists) {
            $this->notifyToolsListChanged();
        }
    }

    public function registerResource(ResourceDefinition $resource, bool $isManual = false): void
    {
        $uri = $resource->getUri();
        $exists = $this->resources->offsetExists($uri);
        $wasManual = isset($this->manualResourceUris[$uri]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("MCP Registry: Ignoring discovered resource '{$uri}' as it conflicts with a manually registered one.");

            return;
        }
        if ($exists) {
            $this->logger->warning('MCP Registry: Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " resource '{$uri}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->resources[$uri] = $resource;
        if ($isManual) {
            $this->manualResourceUris[$uri] = true;
        } elseif ($wasManual) {
            unset($this->manualResourceUris[$uri]);
        }

        if (! $exists) {
            $this->notifyResourcesListChanged();
        }
    }

    public function registerResourceTemplate(ResourceTemplateDefinition $template, bool $isManual = false): void
    {
        $uriTemplate = $template->getUriTemplate();
        $exists = $this->resourceTemplates->offsetExists($uriTemplate);
        $wasManual = isset($this->manualTemplateUris[$uriTemplate]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("MCP Registry: Ignoring discovered template '{$uriTemplate}' as it conflicts with a manually registered one.");

            return;
        }
        if ($exists) {
            $this->logger->warning('MCP Registry: Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " template '{$uriTemplate}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->resourceTemplates[$uriTemplate] = $template;
        if ($isManual) {
            $this->manualTemplateUris[$uriTemplate] = true;
        } elseif ($wasManual) {
            unset($this->manualTemplateUris[$uriTemplate]);
        }
        // No listChanged for templates
    }

    public function registerPrompt(PromptDefinition $prompt, bool $isManual = false): void
    {
        $promptName = $prompt->getName();
        $exists = $this->prompts->offsetExists($promptName);
        $wasManual = isset($this->manualPromptNames[$promptName]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("MCP Registry: Ignoring discovered prompt '{$promptName}' as it conflicts with a manually registered one.");

            return;
        }
        if ($exists) {
            $this->logger->warning('MCP Registry: Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " prompt '{$promptName}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->prompts[$promptName] = $prompt;
        if ($isManual) {
            $this->manualPromptNames[$promptName] = true;
        } elseif ($wasManual) {
            unset($this->manualPromptNames[$promptName]);
        }

        if (! $exists) {
            $this->notifyPromptsListChanged();
        }
    }

    public function loadDiscoveredElementsFromCache(bool $force = false): void
    {
        if ($this->cache === null) {
            $this->logger->debug('MCP Registry: Cache load skipped, cache not available.');
            $this->discoveredElementsLoaded = true;

            return;
        }

        if ($this->discoveredElementsLoaded && ! $force) {
            return; // Already loaded or ran discovery this session
        }

        $this->clearDiscoveredElements(false); // Don't delete cache, just clear internal collections

        try {
            $cached = $this->cache->get(self::DISCOVERED_ELEMENTS_CACHE_KEY);

            if (is_array($cached)) {
                $this->logger->debug('MCP Registry: Loading discovered elements from cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
                $loadCount = 0;

                foreach ($cached['tools'] ?? [] as $toolData) {
                    $toolDefinition = $toolData instanceof ToolDefinition ? $toolData : ToolDefinition::fromArray($toolData);
                    $toolName = $toolDefinition->getName();
                    if (! isset($this->manualToolNames[$toolName])) {
                        $this->tools[$toolName] = $toolDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached tool '{$toolName}' as manual version exists.");
                    }
                }

                foreach ($cached['resources'] ?? [] as $resourceData) {
                    $resourceDefinition = $resourceData instanceof ResourceDefinition ? $resourceData : ResourceDefinition::fromArray($resourceData);
                    $uri = $resourceDefinition->getUri();
                    if (! isset($this->manualResourceUris[$uri])) {
                        $this->resources[$uri] = $resourceDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached resource '{$uri}' as manual version exists.");
                    }
                }

                foreach ($cached['prompts'] ?? [] as $promptData) {
                    $promptDefinition = $promptData instanceof PromptDefinition ? $promptData : PromptDefinition::fromArray($promptData);
                    $promptName = $promptDefinition->getName();
                    if (! isset($this->manualPromptNames[$promptName])) {
                        $this->prompts[$promptName] = $promptDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached prompt '{$promptName}' as manual version exists.");
                    }
                }

                foreach ($cached['resourceTemplates'] ?? [] as $templateData) {
                    $templateDefinition = $templateData instanceof ResourceTemplateDefinition ? $templateData : ResourceTemplateDefinition::fromArray($templateData);
                    $uriTemplate = $templateDefinition->getUriTemplate();
                    if (! isset($this->manualTemplateUris[$uriTemplate])) {
                        $this->resourceTemplates[$uriTemplate] = $templateDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached template '{$uriTemplate}' as manual version exists.");
                    }
                }

                $this->logger->debug("MCP Registry: Loaded {$loadCount} elements from cache.");

                $this->discoveredElementsLoaded = true;
            } elseif ($cached !== null) {
                $this->logger->warning('MCP Registry: Invalid data type found in cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
            } else {
                $this->logger->debug('MCP Registry: Cache miss or empty.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
            }
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP Registry: Invalid cache key used.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);
        } catch (DefinitionException $e) { // Catch potential fromArray errors
            $this->logger->error('MCP Registry: Error hydrating definition from cache.', ['exception' => $e]);
            // Clear cache on hydration error? Or just log and continue? Let's log and skip cache load.
            $this->initializeCollections(); // Reset collections if hydration failed
        } catch (Throwable $e) {
            $this->logger->error('MCP Registry: Unexpected error loading from cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);
        }
    }

    public function saveDiscoveredElementsToCache(): bool
    {
        if ($this->cache === null) {
            $this->logger->debug('MCP Registry: Cache save skipped, cache not available.');

            return false;
        }

        $discoveredData = [
            'tools' => [],
            'resources' => [],
            'prompts' => [],
            'resourceTemplates' => [],
        ];

        foreach ($this->tools as $name => $tool) {
            if (! isset($this->manualToolNames[$name])) {
                $discoveredData['tools'][$name] = $tool;
            }
        }

        foreach ($this->resources as $uri => $resource) {
            if (! isset($this->manualResourceUris[$uri])) {
                $discoveredData['resources'][$uri] = $resource;
            }
        }

        foreach ($this->prompts as $name => $prompt) {
            if (! isset($this->manualPromptNames[$name])) {
                $discoveredData['prompts'][$name] = $prompt;
            }
        }

        foreach ($this->resourceTemplates as $uriTemplate => $template) {
            if (! isset($this->manualTemplateUris[$uriTemplate])) {
                $discoveredData['resourceTemplates'][$uriTemplate] = $template;
            }
        }

        try {
            $success = $this->cache->set(self::DISCOVERED_ELEMENTS_CACHE_KEY, $discoveredData);

            if ($success) {
                $this->logger->debug('MCP Registry: Elements saved to cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
            } else {
                $this->logger->warning('MCP Registry: Cache set operation returned false.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
            }

            return $success;
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('MCP Registry: Invalid cache key or value during save.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);

            return false;
        } catch (Throwable $e) {
            $this->logger->error('MCP Registry: Unexpected error saving to cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);

            return false;
        }
    }

    public function clearDiscoveredElements(bool $deleteFromCache = true): void
    {
        $this->logger->debug('Clearing discovered elements...', ['deleteCacheFile' => $deleteFromCache]);

        if ($deleteFromCache && $this->cache !== null) {
            try {
                $this->cache->delete(self::DISCOVERED_ELEMENTS_CACHE_KEY);
                $this->logger->info('MCP Registry: Discovered elements cache cleared.');
            } catch (Throwable $e) {
                $this->logger->error('MCP Registry: Error clearing discovered elements cache.', ['exception' => $e]);
            }
        }

        $clearCount = 0;

        foreach ($this->tools as $name => $tool) {
            if (! isset($this->manualToolNames[$name])) {
                unset($this->tools[$name]);
                $clearCount++;
            }
        }
        foreach ($this->resources as $uri => $resource) {
            if (! isset($this->manualResourceUris[$uri])) {
                unset($this->resources[$uri]);
                $clearCount++;
            }
        }
        foreach ($this->prompts as $name => $prompt) {
            if (! isset($this->manualPromptNames[$name])) {
                unset($this->prompts[$name]);
                $clearCount++;
            }
        }
        foreach ($this->resourceTemplates as $uriTemplate => $template) {
            if (! isset($this->manualTemplateUris[$uriTemplate])) {
                unset($this->resourceTemplates[$uriTemplate]);
                $clearCount++;
            }
        }

        $this->discoveredElementsLoaded = false;
        $this->logger->debug("Removed {$clearCount} discovered elements from internal registry.");
    }

    public function findTool(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    public function findPrompt(string $name): ?PromptDefinition
    {
        return $this->prompts[$name] ?? null;
    }

    public function findResourceByUri(string $uri): ?ResourceDefinition
    {
        return $this->resources[$uri] ?? null;
    }

    public function findResourceTemplateByUri(string $uri): ?array
    {
        foreach ($this->resourceTemplates as $templateDefinition) {
            try {
                $matcher = new UriTemplateMatcher($templateDefinition->getUriTemplate());
                $variables = $matcher->match($uri);

                if ($variables !== null) {
                    $this->logger->debug('MCP Registry: Matched URI to template.', ['uri' => $uri, 'template' => $templateDefinition->getUriTemplate()]);

                    return ['definition' => $templateDefinition, 'variables' => $variables];
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Invalid resource template encountered during matching', [
                    'template' => $templateDefinition->getUriTemplate(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }
        $this->logger->debug('MCP Registry: No template matched URI.', ['uri' => $uri]);

        return null;
    }

    /** @return ArrayObject<string, ToolDefinition> */
    public function allTools(): ArrayObject
    {
        return $this->tools;
    }

    /** @return ArrayObject<string, ResourceDefinition> */
    public function allResources(): ArrayObject
    {
        return $this->resources;
    }

    /** @return ArrayObject<string, PromptDefinition> */
    public function allPrompts(): ArrayObject
    {
        return $this->prompts;
    }

    /** @return ArrayObject<string, ResourceTemplateDefinition> */
    public function allResourceTemplates(): ArrayObject
    {
        return $this->resourceTemplates;
    }
}
