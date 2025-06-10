<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use PhpMcp\Schema\Prompt;
use PhpMcp\Schema\Resource;
use PhpMcp\Schema\ResourceTemplate;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Exception\DefinitionException;
use PhpMcp\Server\Support\Handler;
use PhpMcp\Server\Support\UriTemplateMatcher;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Throwable;

class Registry implements EventEmitterInterface
{
    use EventEmitterTrait;

    private const DISCOVERED_ELEMENTS_CACHE_KEY = 'mcp_server_discovered_elements';

    /** @var array<string, array{tool: Tool, handler: Handler}> */
    private array $tools = [];

    /** @var array<string, array{resource: Resource, handler: Handler}> */
    private array $resources = [];

    /** @var array<string, array{prompt: Prompt, handler: Handler}> */
    private array $prompts = [];

    /** @var array<string, array{resourceTemplate: ResourceTemplate, handler: Handler}> */
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
                    if (!isset($toolData['tool']) || !isset($toolData['handler'])) {
                        $this->logger->warning('Invalid tool data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'data' => $toolData]);
                        continue;
                    }

                    $toolName = $toolData['tool']['name'];
                    if (! isset($this->manualToolNames[$toolName])) {
                        $this->tools[$toolName] = [
                            'tool' => Tool::fromArray($toolData['tool']),
                            'handler' => Handler::fromArray($toolData['handler']),
                        ];
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached tool '{$toolName}' as manual version exists.");
                    }
                }

                foreach ($cached['resources'] ?? [] as $resourceData) {
                    if (!isset($resourceData['resource']) || !isset($resourceData['handler'])) {
                        $this->logger->warning('Invalid resource data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'data' => $resourceData]);
                        continue;
                    }

                    $uri = $resourceData['resource']['uri'];
                    if (! isset($this->manualResourceUris[$uri])) {
                        $this->resources[$uri] = [
                            'resource' => Resource::fromArray($resourceData['resource']),
                            'handler' => Handler::fromArray($resourceData['handler']),
                        ];
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached resource '{$uri}' as manual version exists.");
                    }
                }

                foreach ($cached['prompts'] ?? [] as $promptData) {
                    if (!isset($promptData['prompt']) || !isset($promptData['handler'])) {
                        $this->logger->warning('Invalid prompt data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'data' => $promptData]);
                        continue;
                    }

                    $promptName = $promptData['prompt']['name'];
                    if (! isset($this->manualPromptNames[$promptName])) {
                        $this->prompts[$promptName] = [
                            'prompt' => Prompt::fromArray($promptData['prompt']),
                            'handler' => Handler::fromArray($promptData['handler']),
                        ];
                        $loadCount++;
                    } else {
                        $this->logger->debug("Skipping cached prompt '{$promptName}' as manual version exists.");
                    }
                }

                foreach ($cached['resourceTemplates'] ?? [] as $templateData) {
                    if (!isset($templateData['resourceTemplate']) || !isset($templateData['handler'])) {
                        $this->logger->warning('Invalid resource template data found in registry cache, ignoring.', ['key' => self::DISCOVERED_ELEMENTS_CACHE_KEY, 'data' => $templateData]);
                        continue;
                    }

                    $uriTemplate = $templateData['resourceTemplate']['uriTemplate'];
                    if (! isset($this->manualTemplateUris[$uriTemplate])) {
                        $this->resourceTemplates[$uriTemplate] = [
                            'resourceTemplate' => ResourceTemplate::fromArray($templateData['resourceTemplate']),
                            'handler' => Handler::fromArray($templateData['handler']),
                        ];
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

    public function registerTool(Tool $tool, Handler $handler, bool $isManual = false): void
    {
        $toolName = $tool->name;
        $exists = isset($this->tools[$toolName]);
        $wasManual = isset($this->manualToolNames[$toolName]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("MCP Registry: Ignoring discovered tool '{$toolName}' as it conflicts with a manually registered one.");

            return; // Manual registration takes precedence
        }

        if ($exists) {
            $this->logger->warning('MCP Registry: Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " tool '{$toolName}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->tools[$toolName] = [
            'tool' => $tool,
            'handler' => $handler,
        ];

        if ($isManual) {
            $this->manualToolNames[$toolName] = true;
        } elseif ($wasManual) {
            unset($this->manualToolNames[$toolName]);
        }

        $this->checkAndEmitChange('tools', $this->tools);
    }

    public function registerResource(Resource $resource, Handler $handler, bool $isManual = false): void
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

        $this->resources[$uri] = [
            'resource' => $resource,
            'handler' => $handler,
        ];

        if ($isManual) {
            $this->manualResourceUris[$uri] = true;
        } elseif ($wasManual) {
            unset($this->manualResourceUris[$uri]);
        }

        $this->checkAndEmitChange('resources', $this->resources);
    }

    public function registerResourceTemplate(ResourceTemplate $template, Handler $handler, bool $isManual = false): void
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

        $this->resourceTemplates[$uriTemplate] = [
            'resourceTemplate' => $template,
            'handler' => $handler,
        ];

        if ($isManual) {
            $this->manualTemplateUris[$uriTemplate] = true;
        } elseif ($wasManual) {
            unset($this->manualTemplateUris[$uriTemplate]);
        }

        // No listChanged for templates
    }

    public function registerPrompt(Prompt $prompt, Handler $handler, bool $isManual = false): void
    {
        $promptName = $prompt->name;
        $exists = isset($this->prompts[$promptName]);
        $wasManual = isset($this->manualPromptNames[$promptName]);

        if ($exists && ! $isManual && $wasManual) {
            $this->logger->debug("MCP Registry: Ignoring discovered prompt '{$promptName}' as it conflicts with a manually registered one.");

            return;
        }
        if ($exists) {
            $this->logger->warning('MCP Registry: Replacing existing ' . ($wasManual ? 'manual' : 'discovered') . " prompt '{$promptName}' with " . ($isManual ? 'manual' : 'discovered') . ' definition.');
        }

        $this->prompts[$promptName] = [
            'prompt' => $prompt,
            'handler' => $handler,
        ];

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
                $discoveredData['tools'][$name] = [
                    'tool' => $tool['tool']->toArray(),
                    'handler' => $tool['handler']->toArray(),
                ];
            }
        }

        foreach ($this->resources as $uri => $resource) {
            if (! isset($this->manualResourceUris[$uri])) {
                $discoveredData['resources'][$uri] = [
                    'resource' => $resource['resource']->toArray(),
                    'handler' => $resource['handler']->toArray(),
                ];
            }
        }

        foreach ($this->prompts as $name => $prompt) {
            if (! isset($this->manualPromptNames[$name])) {
                $discoveredData['prompts'][$name] = [
                    'prompt' => $prompt['prompt']->toArray(),
                    'handler' => $prompt['handler']->toArray(),
                ];
            }
        }

        foreach ($this->resourceTemplates as $uriTemplate => $template) {
            if (! isset($this->manualTemplateUris[$uriTemplate])) {
                $discoveredData['resourceTemplates'][$uriTemplate] = [
                    'resourceTemplate' => $template['resourceTemplate']->toArray(),
                    'handler' => $template['handler']->toArray(),
                ];
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

        if ($clearCount > 0) {
            $this->logger->debug("Removed {$clearCount} discovered elements from internal registry.");
        }
    }

    /** @return array{tool: Tool, handler: Handler}|null */
    public function getTool(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array{
     *      resource: Resource,
     *      handler: Handler,
     *      variables: array<string, string>,
     * }|null */
    public function getResource(string $uri, bool $includeTemplates = true): ?array
    {
        $registration = $this->resources[$uri] ?? null;
        if ($registration) {
            $registration['variables'] = [];
            return $registration;
        }

        if (! $includeTemplates) {
            return null;
        }

        foreach ($this->resourceTemplates as $template) {
            try {
                $matcher = new UriTemplateMatcher($template['resourceTemplate']->uriTemplate);
                $variables = $matcher->match($uri);
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Invalid resource template encountered during matching', [
                    'template' => $template['resourceTemplate']->uriTemplate,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($variables !== null) {
                return [
                    'resource' => $template['resourceTemplate'],
                    'handler' => $template['handler'],
                    'variables' => $variables,
                ];
            }
        }

        $this->logger->debug('No resource matched URI.', ['uri' => $uri]);

        return null;
    }

    /** @return array{prompt: Prompt, handler: Handler}|null */
    public function getPrompt(string $name): ?array
    {
        return $this->prompts[$name] ?? null;
    }

    /** @return array<string, Tool> */
    public function getTools(): array
    {
        return array_map(fn ($registration) => $registration['tool'], $this->tools);
    }

    /** @return array<string, Resource> */
    public function getResources(): array
    {
        return array_map(fn ($registration) => $registration['resource'], $this->resources);
    }

    /** @return array<string, Prompt> */
    public function getPrompts(): array
    {
        return array_map(fn ($registration) => $registration['prompt'], $this->prompts);
    }

    /** @return array<string, ResourceTemplate> */
    public function getResourceTemplates(): array
    {
        return array_map(fn ($registration) => $registration['resourceTemplate'], $this->resourceTemplates);
    }
}
