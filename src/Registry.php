<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use PhpMcp\Schema\Prompt;
use PhpMcp\Schema\Resource;
use PhpMcp\Schema\ResourceTemplate;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Elements\RegisteredPrompt;
use PhpMcp\Server\Elements\RegisteredResource;
use PhpMcp\Server\Elements\RegisteredResourceTemplate;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Server\Exception\DefinitionException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Throwable;

class Registry implements EventEmitterInterface
{
    use EventEmitterTrait;

    private const DISCOVERED_ELEMENTS_CACHE_KEY = 'mcp_server_discovered_elements';

    /** @var array<string, RegisteredTool> */
    private array $tools = [];

    /** @var array<string, RegisteredResource> */
    private array $resources = [];

    /** @var array<string, RegisteredPrompt> */
    private array $prompts = [];

    /** @var array<string, RegisteredResourceTemplate> */
    private array $resourceTemplates = [];

    private array $listHashes = [
        'tools' => '',
        'resources' => '',
        'resource_templates' => '',
        'prompts' => '',
    ];

    private bool $notificationsEnabled = true;

    public function __construct(
        protected LoggerInterface $logger,
        protected ?CacheInterface $cache = null,
    ) {
        $this->load();
        $this->computeAllHashes();
    }

    /**
     * Compute hashes for all lists for change detection
     */
    private function computeAllHashes(): void
    {
        $this->listHashes['tools'] = $this->computeHash($this->tools);
        $this->listHashes['resources'] = $this->computeHash($this->resources);
        $this->listHashes['resource_templates'] = $this->computeHash($this->resourceTemplates);
        $this->listHashes['prompts'] = $this->computeHash($this->prompts);
    }

    /**
     * Compute a stable hash for a collection
     */
    private function computeHash(array $collection): string
    {
        if (empty($collection)) {
            return '';
        }

        ksort($collection);
        return md5(json_encode($collection));
    }

    public function load(): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->clear(false);

        try {
            $cached = $this->cache->get(self::DISCOVERED_ELEMENTS_CACHE_KEY);

            if (!is_array($cached)) {
                $this->logger->warning('Invalid or missing data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
                return;
            }

            $loadCount = 0;

            foreach ($cached['tools'] ?? [] as $toolData) {
                $cachedTool = RegisteredTool::fromArray(json_decode($toolData, true));
                if ($cachedTool === false) {
                    $this->logger->warning('Invalid or missing data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
                    continue;
                }

                $toolName = $cachedTool->schema->name;
                $existingTool = $this->tools[$toolName] ?? null;

                if ($existingTool && $existingTool->isManual) {
                    $this->logger->debug("Skipping cached tool '{$toolName}' as manual version exists.");
                    continue;
                }

                $this->tools[$toolName] = $cachedTool;
                $loadCount++;
            }

            foreach ($cached['resources'] ?? [] as $resourceData) {
                $cachedResource = RegisteredResource::fromArray(json_decode($resourceData, true));
                if ($cachedResource === false) {
                    $this->logger->warning('Invalid or missing data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
                    continue;
                }

                $uri = $cachedResource->schema->uri;
                $existingResource = $this->resources[$uri] ?? null;

                if ($existingResource && $existingResource->isManual) {
                    $this->logger->debug("Skipping cached resource '{$uri}' as manual version exists.");
                    continue;
                }

                $this->resources[$uri] = $cachedResource;
                $loadCount++;
            }

            foreach ($cached['prompts'] ?? [] as $promptData) {
                $cachedPrompt = RegisteredPrompt::fromArray(json_decode($promptData, true));
                if ($cachedPrompt === false) {
                    $this->logger->warning('Invalid or missing data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
                    continue;
                }

                $promptName = $cachedPrompt->schema->name;
                $existingPrompt = $this->prompts[$promptName] ?? null;

                if ($existingPrompt && $existingPrompt->isManual) {
                    $this->logger->debug("Skipping cached prompt '{$promptName}' as manual version exists.");
                    continue;
                }

                $this->prompts[$promptName] = $cachedPrompt;
                $loadCount++;
            }

            foreach ($cached['resourceTemplates'] ?? [] as $templateData) {
                $cachedTemplate = RegisteredResourceTemplate::fromArray(json_decode($templateData, true));
                if ($cachedTemplate === false) {
                    $this->logger->warning('Invalid or missing data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
                    continue;
                }

                $uriTemplate = $cachedTemplate->schema->uriTemplate;
                $existingTemplate = $this->resourceTemplates[$uriTemplate] ?? null;

                if ($existingTemplate && $existingTemplate->isManual) {
                    $this->logger->debug("Skipping cached template '{$uriTemplate}' as manual version exists.");
                    continue;
                }

                $this->resourceTemplates[$uriTemplate] = $cachedTemplate;
                $loadCount++;
            }

            $this->logger->debug("Loaded {$loadCount} elements from cache.");
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('Invalid registry cache key used.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error loading from cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);
        }
    }

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $toolName = $tool->name;
        $existing = $this->tools[$toolName] ?? null;

        if ($existing && ! $isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered tool '{$toolName}' as it conflicts with a manually registered one.");

            return;
        }

        $this->tools[$toolName] = RegisteredTool::make($tool, $handler, $isManual);

        $this->checkAndEmitChange('tools', $this->tools);
    }

    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $uri = $resource->uri;
        $existing = $this->resources[$uri] ?? null;

        if ($existing && ! $isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered resource '{$uri}' as it conflicts with a manually registered one.");

            return;
        }

        $this->resources[$uri] = RegisteredResource::make($resource, $handler, $isManual);

        $this->checkAndEmitChange('resources', $this->resources);
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $uriTemplate = $template->uriTemplate;
        $existing = $this->resourceTemplates[$uriTemplate] ?? null;

        if ($existing && ! $isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered template '{$uriTemplate}' as it conflicts with a manually registered one.");

            return;
        }

        $this->resourceTemplates[$uriTemplate] = RegisteredResourceTemplate::make($template, $handler, $isManual, $completionProviders);

        $this->checkAndEmitChange('resource_templates', $this->resourceTemplates);
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $promptName = $prompt->name;
        $existing = $this->prompts[$promptName] ?? null;

        if ($existing && ! $isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered prompt '{$promptName}' as it conflicts with a manually registered one.");

            return;
        }

        $this->prompts[$promptName] = RegisteredPrompt::make($prompt, $handler, $isManual, $completionProviders);

        $this->checkAndEmitChange('prompts', $this->prompts);
    }

    public function enableNotifications(): void
    {
        $this->notificationsEnabled = true;
    }

    public function disableNotifications(): void
    {
        $this->notificationsEnabled = false;
    }

    /**
     * Check if a list has changed and emit event if needed
     */
    private function checkAndEmitChange(string $listType, array $collection): void
    {
        if (! $this->notificationsEnabled) {
            return;
        }

        $newHash = $this->computeHash($collection);

        if ($newHash !== $this->listHashes[$listType]) {
            $this->listHashes[$listType] = $newHash;
            $this->emit('list_changed', [$listType]);
        }
    }

    public function save(): bool
    {
        if ($this->cache === null) {
            return false;
        }

        $discoveredData = [
            'tools' => [],
            'resources' => [],
            'prompts' => [],
            'resourceTemplates' => [],
        ];

        foreach ($this->tools as $name => $tool) {
            if (! $tool->isManual) {
                if ($tool->handler instanceof \Closure) {
                    $this->logger->warning("Skipping closure tool from cache: {$name}");
                    continue;
                }
                $discoveredData['tools'][$name] = json_encode($tool);
            }
        }

        foreach ($this->resources as $uri => $resource) {
            if (! $resource->isManual) {
                if ($resource->handler instanceof \Closure) {
                    $this->logger->warning("Skipping closure resource from cache: {$uri}");
                    continue;
                }
                $discoveredData['resources'][$uri] = json_encode($resource);
            }
        }

        foreach ($this->prompts as $name => $prompt) {
            if (! $prompt->isManual) {
                if ($prompt->handler instanceof \Closure) {
                    $this->logger->warning("Skipping closure prompt from cache: {$name}");
                    continue;
                }
                $discoveredData['prompts'][$name] = json_encode($prompt);
            }
        }

        foreach ($this->resourceTemplates as $uriTemplate => $template) {
            if (! $template->isManual) {
                if ($template->handler instanceof \Closure) {
                    $this->logger->warning("Skipping closure template from cache: {$uriTemplate}");
                    continue;
                }
                $discoveredData['resourceTemplates'][$uriTemplate] = json_encode($template);
            }
        }

        try {
            $success = $this->cache->set(self::DISCOVERED_ELEMENTS_CACHE_KEY, $discoveredData);

            if ($success) {
                $this->logger->debug('Registry elements saved to cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
            } else {
                $this->logger->warning('Registry cache set operation returned false.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
            }

            return $success;
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('Invalid cache key or value during save.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);

            return false;
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error saving to cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);

            return false;
        }
    }

    /** Checks if any elements (manual or discovered) are currently registered. */
    public function hasElements(): bool
    {
        return ! empty($this->tools)
            || ! empty($this->resources)
            || ! empty($this->prompts)
            || ! empty($this->resourceTemplates);
    }

    /**
     * Clear discovered elements from registry
     * 
     * @param bool $includeCache Whether to clear the cache as well (default: true)
     */
    public function clear(bool $includeCache = true): void
    {
        if ($includeCache && $this->cache !== null) {
            try {
                $this->cache->delete(self::DISCOVERED_ELEMENTS_CACHE_KEY);
                $this->logger->debug('Registry cache cleared.');
            } catch (Throwable $e) {
                $this->logger->error('Error clearing registry cache.', ['exception' => $e]);
            }
        }

        $clearCount = 0;

        foreach ($this->tools as $name => $tool) {
            if (! $tool->isManual) {
                unset($this->tools[$name]);
                $clearCount++;
            }
        }
        foreach ($this->resources as $uri => $resource) {
            if (! $resource->isManual) {
                unset($this->resources[$uri]);
                $clearCount++;
            }
        }
        foreach ($this->prompts as $name => $prompt) {
            if (! $prompt->isManual) {
                unset($this->prompts[$name]);
                $clearCount++;
            }
        }
        foreach ($this->resourceTemplates as $uriTemplate => $template) {
            if (! $template->isManual) {
                unset($this->resourceTemplates[$uriTemplate]);
                $clearCount++;
            }
        }

        if ($clearCount > 0) {
            $this->logger->debug("Removed {$clearCount} discovered elements from internal registry.");
        }
    }

    /** @return RegisteredTool|null */
    public function getTool(string $name): ?RegisteredTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return RegisteredResource|RegisteredResourceTemplate|null */
    public function getResource(string $uri, bool $includeTemplates = true): RegisteredResource|RegisteredResourceTemplate|null
    {
        $registration = $this->resources[$uri] ?? null;
        if ($registration) {
            return $registration;
        }

        if (! $includeTemplates) {
            return null;
        }

        foreach ($this->resourceTemplates as $template) {
            if ($template->matches($uri)) {
                return $template;
            }
        }

        $this->logger->debug('No resource matched URI.', ['uri' => $uri]);

        return null;
    }

    /** @return RegisteredResourceTemplate|null */
    public function getResourceTemplate(string $uriTemplate): ?RegisteredResourceTemplate
    {
        return $this->resourceTemplates[$uriTemplate] ?? null;
    }

    /** @return RegisteredPrompt|null */
    public function getPrompt(string $name): ?RegisteredPrompt
    {
        return $this->prompts[$name] ?? null;
    }

    /** @return array<string, Tool> */
    public function getTools(): array
    {
        return array_map(fn($tool) => $tool->schema, $this->tools);
    }

    /** @return array<string, Resource> */
    public function getResources(): array
    {
        return array_map(fn($resource) => $resource->schema, $this->resources);
    }

    /** @return array<string, Prompt> */
    public function getPrompts(): array
    {
        return array_map(fn($prompt) => $prompt->schema, $this->prompts);
    }

    /** @return array<string, ResourceTemplate> */
    public function getResourceTemplates(): array
    {
        return array_map(fn($template) => $template->schema, $this->resourceTemplates);
    }
}
