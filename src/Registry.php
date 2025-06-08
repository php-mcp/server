<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\Support\UriTemplateMatcher;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Throwable;

class Registry implements EventEmitterInterface
{
    use EventEmitterTrait;

    private const DISCOVERED_ELEMENTS_CACHE_KEY = 'mcp_server_discovered_elements';

    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    /** @var array<string, ResourceDefinition> */
    private array $resources = [];

    /** @var array<string, PromptDefinition> */
    private array $prompts = [];

    /** @var array<string, ResourceTemplateDefinition> */
    private array $resourceTemplates = [];

    /** @var array<string, true> */
    private array $manualToolNames = [];

    /** @var array<string, true> */
    private array $manualResourceUris = [];

    /** @var array<string, true> */
    private array $manualPromptNames = [];

    /** @var array<string, true> */
    private array $manualTemplateUris = [];

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

        $this->clear();

        try {
            $cached = $this->cache->get(self::DISCOVERED_ELEMENTS_CACHE_KEY);

            if (is_array($cached)) {
                $loadCount = 0;

                foreach ($cached['tools'] ?? [] as $toolData) {
                    $toolDefinition = $toolData instanceof ToolDefinition ? $toolData : ToolDefinition::fromArray($toolData);
                    $toolName = $toolDefinition->toolName;
                    if (! isset($this->manualToolNames[$toolName])) {
                        $this->tools[$toolName] = $toolDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached tool '{$toolName}' as manual version exists.");
                    }
                }

                foreach ($cached['resources'] ?? [] as $resourceData) {
                    $resourceDefinition = $resourceData instanceof ResourceDefinition ? $resourceData : ResourceDefinition::fromArray($resourceData);
                    $uri = $resourceDefinition->uri;
                    if (! isset($this->manualResourceUris[$uri])) {
                        $this->resources[$uri] = $resourceDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached resource '{$uri}' as manual version exists.");
                    }
                }

                foreach ($cached['prompts'] ?? [] as $promptData) {
                    $promptDefinition = $promptData instanceof PromptDefinition ? $promptData : PromptDefinition::fromArray($promptData);
                    $promptName = $promptDefinition->promptName;
                    if (! isset($this->manualPromptNames[$promptName])) {
                        $this->prompts[$promptName] = $promptDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached prompt '{$promptName}' as manual version exists.");
                    }
                }

                foreach ($cached['resourceTemplates'] ?? [] as $templateData) {
                    $templateDefinition = $templateData instanceof ResourceTemplateDefinition ? $templateData : ResourceTemplateDefinition::fromArray($templateData);
                    $uriTemplate = $templateDefinition->uriTemplate;
                    if (! isset($this->manualTemplateUris[$uriTemplate])) {
                        $this->resourceTemplates[$uriTemplate] = $templateDefinition;
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached template '{$uriTemplate}' as manual version exists.");
                    }
                }

                $this->logger->debug("Loaded {$loadCount} elements from cache.");
            } elseif ($cached !== null) {
                $this->logger->warning('Invalid data type found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'type' => gettype($cached)]);
            } else {
                $this->logger->debug('Cache miss or empty.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY]);
            }
        } catch (CacheInvalidArgumentException $e) {
            $this->logger->error('Invalid registry cache key used.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);
        } catch (DefinitionException $e) {
            $this->logger->error('Error hydrating definition from cache.', ['exception' => $e]);
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error loading from cache.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'exception' => $e]);
        }
    }

    public function registerTool(ToolDefinition $tool, bool $isManual = false): void
    {
        $toolName = $tool->toolName;
        $exists = isset($this->tools[$toolName]);
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

        $this->checkAndEmitChange('tools', $this->tools);
    }

    public function registerResource(ResourceDefinition $resource, bool $isManual = false): void
    {
        $uri = $resource->uri;
        $exists = isset($this->resources[$uri]);
        $wasManual = isset($this->manualResourceUris[$uri]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("Ignoring discovered resource '{$uri}' as it conflicts with a manually registered one.");

            return;
        }
        if ($exists) {
            $this->logger->warning('Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " resource '{$uri}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->resources[$uri] = $resource;

        if ($isManual) {
            $this->manualResourceUris[$uri] = true;
        } elseif ($wasManual) {
            unset($this->manualResourceUris[$uri]);
        }

        $this->checkAndEmitChange('resources', $this->resources);
    }

    public function registerResourceTemplate(ResourceTemplateDefinition $template, bool $isManual = false): void
    {
        $uriTemplate = $template->uriTemplate;
        $exists = isset($this->resourceTemplates[$uriTemplate]);
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
        $promptName = $prompt->promptName;
        $exists = isset($this->prompts[$promptName]);
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

    public function clear(): void
    {
        if ($this->cache !== null) {
            try {
                $this->cache->delete(self::DISCOVERED_ELEMENTS_CACHE_KEY);
                $this->logger->debug('Registry cache cleared.');
            } catch (Throwable $e) {
                $this->logger->error('Error clearing registry cache.', ['exception' => $e]);
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
                $matcher = new UriTemplateMatcher($templateDefinition->uriTemplate);
                $variables = $matcher->match($uri);

                if ($variables !== null) {
                    $this->logger->debug('MCP Registry: Matched URI to template.', ['uri' => $uri, 'template' => $templateDefinition->uriTemplate]);

                    return ['definition' => $templateDefinition, 'variables' => $variables];
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Invalid resource template encountered during matching', [
                    'template' => $templateDefinition->uriTemplate,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }
        $this->logger->debug('MCP Registry: No template matched URI.', ['uri' => $uri]);

        return null;
    }

    /** @return array<string, ToolDefinition> */
    public function getTools(): array
    {
        return $this->tools;
    }

    /** @return array<string, ResourceDefinition> */
    public function getResources(): array
    {
        return $this->resources;
    }

    /** @return array<string, PromptDefinition> */
    public function getPrompts(): array
    {
        return $this->prompts;
    }

    /** @return array<string, ResourceTemplateDefinition> */
    public function getResourceTemplates(): array
    {
        return $this->resourceTemplates;
    }
}
