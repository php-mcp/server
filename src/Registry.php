<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use ArrayObject;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Support\UriTemplateMatcher;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

class Registry
{
    /** @var ArrayObject<string, ToolDefinition> */
    private ArrayObject $tools;

    /** @var ArrayObject<string, ResourceDefinition> */
    private ArrayObject $resources;

    /** @var ArrayObject<string, PromptDefinition> */
    private ArrayObject $prompts;

    /** @var ArrayObject<string, ResourceTemplateDefinition> */
    private ArrayObject $resourceTemplates;

    private bool $isLoaded = false;

    // --- Notification Callbacks ---
    /** @var callable|null */
    private $notifyToolsChanged = null;

    /** @var callable|null */
    private $notifyResourcesChanged = null;

    /** @var callable|null */
    private $notifyPromptsChanged = null;
    // Add others like templates if needed

    private string $cacheKey = '';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly TransportState $transportState,
        private readonly ?string $cachePrefix = 'mcp_'
    ) {
        $this->initializeEmptyCollections();
        $this->initializeDefaultNotifiers();

        $this->cacheKey = $cachePrefix.'elements';
    }

    private function initializeEmptyCollections(): void
    {
        $this->tools = new ArrayObject();
        $this->resources = new ArrayObject();
        $this->prompts = new ArrayObject();
        $this->resourceTemplates = new ArrayObject();
    }

    private function initializeDefaultNotifiers(): void
    {
        $this->notifyToolsChanged = function () {
            $notification = Notification::make('notifications/tools/list_changed');
            $this->transportState->queueMessageForAll($notification);
        };

        $this->notifyResourcesChanged = function () {
            $notification = Notification::make('notifications/resources/list_changed');
            $this->transportState->queueMessageForAll($notification);
        };

        $this->notifyPromptsChanged = function () {
            $notification = Notification::make('notifications/prompts/list_changed');
            $this->transportState->queueMessageForAll($notification);
        };
    }

    // --- Public Setters to Override Defaults ---
    public function setToolsChangedNotifier(?callable $notifier): void
    {
        $this->notifyToolsChanged = $notifier;
    }

    public function setResourcesChangedNotifier(?callable $notifier): void
    {
        $this->notifyResourcesChanged = $notifier;
    }

    public function setPromptsChangedNotifier(?callable $notifier): void
    {
        $this->notifyPromptsChanged = $notifier;
    }

    public function loadElements(): void
    {
        if ($this->isLoaded) {
            return;
        }

        $cached = $this->cache->get($this->cacheKey);

        if (is_array($cached) && isset($cached['tools'])) {
            $this->logger->debug('MCP: Loading elements from cache.', ['key' => $this->cacheKey]);
            $this->setElementsFromArray($cached);
            $this->isLoaded = true;
        }

        if (! $this->isLoaded) {
            $this->initializeEmptyCollections();
            $this->isLoaded = true;
        }
    }

    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }

    public function registerTool(ToolDefinition $tool): void
    {
        $this->loadElements();
        $toolName = $tool->getName();
        $alreadyExists = $this->tools->offsetExists($toolName);
        if ($alreadyExists) {
            $this->logger->warning("MCP: Replacing existing tool '{$toolName}'");
        }
        $this->tools[$toolName] = $tool;

        if (! $alreadyExists && $this->notifyToolsChanged) {
            ($this->notifyToolsChanged)();
        }
    }

    public function registerResource(ResourceDefinition $resource): void
    {
        $this->loadElements();
        $uri = $resource->getUri();
        $alreadyExists = $this->resources->offsetExists($uri);
        if ($alreadyExists) {
            $this->logger->warning("MCP: Replacing existing resource '{$uri}'");
        }
        $this->resources[$uri] = $resource;

        if (! $alreadyExists && $this->notifyResourcesChanged) {
            ($this->notifyResourcesChanged)();
        }
    }

    public function registerResourceTemplate(ResourceTemplateDefinition $template): void
    {
        $this->loadElements();
        $uriTemplate = $template->getUriTemplate();
        $alreadyExists = $this->resourceTemplates->offsetExists($uriTemplate);
        if ($alreadyExists) {
            $this->logger->warning("MCP: Replacing existing resource template '{$uriTemplate}'");
        }
        $this->resourceTemplates[$uriTemplate] = $template;
    }

    public function registerPrompt(PromptDefinition $prompt): void
    {
        $this->loadElements();
        $promptName = $prompt->getName();
        $alreadyExists = $this->prompts->offsetExists($promptName);
        if ($alreadyExists) {
            $this->logger->warning("MCP: Replacing existing prompt '{$promptName}'");
        }
        $this->prompts[$promptName] = $prompt;

        if (! $alreadyExists && $this->notifyPromptsChanged) {
            ($this->notifyPromptsChanged)();
        }
    }

    public function cacheElements(): bool
    {
        $data = [
            'tools' => $this->tools->getArrayCopy(),
            'resources' => $this->resources->getArrayCopy(),
            'prompts' => $this->prompts->getArrayCopy(),
            'resourceTemplates' => $this->resourceTemplates->getArrayCopy(),
        ];
        try {
            $this->cache->set($this->cacheKey, $data);
            $this->logger->debug('MCP: Elements saved to cache.', ['key' => $this->cacheKey]);

            return true;
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $this->logger->error('MCP: Failed to save elements to cache.', ['key' => $this->cacheKey, 'exception' => $e]);

            return false;
        }
    }

    public function clearCache(): void
    {
        try {
            $this->cache->delete($this->cacheKey);
            $this->initializeEmptyCollections();
            $this->isLoaded = false;
            $this->logger->debug('MCP: Element cache cleared.');

            if ($this->notifyToolsChanged) {
                ($this->notifyToolsChanged)();
            }
            if ($this->notifyResourcesChanged) {
                ($this->notifyResourcesChanged)();
            }
            if ($this->notifyPromptsChanged) {
                ($this->notifyPromptsChanged)();
            }

        } catch (Throwable $e) {
            $this->logger->error('MCP: Failed to clear element cache.', ['exception' => $e]);
        }
    }

    private function setElementsFromArray(array $data): void
    {
        $this->tools = new ArrayObject($data['tools'] ?? []);
        $this->resources = new ArrayObject($data['resources'] ?? []);
        $this->prompts = new ArrayObject($data['prompts'] ?? []);
        $this->resourceTemplates = new ArrayObject($data['resourceTemplates'] ?? []);
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
            /** @var ResourceTemplateDefinition $templateDefinition */
            $matcher = new UriTemplateMatcher($templateDefinition->getUriTemplate());
            $variables = $matcher->match($uri);

            if ($variables !== null) {
                $this->logger->debug('MCP: Matched URI to template.', ['uri' => $uri, 'template' => $templateDefinition->getUriTemplate()]);

                return [
                    'definition' => $templateDefinition,
                    'variables' => $variables,
                ];
            }
        }
        $this->logger->debug('MCP: No template matched URI.', ['uri' => $uri]);

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

    // --- Methods for Server Responses ---

    /**
     * Get all tool definitions formatted as arrays for MCP responses.
     *
     * @return list<array> An array of tool definition arrays.
     */
    public function getToolDefinitionsAsArray(): array
    {
        $result = [];
        foreach ($this->tools as $tool) {
            /** @var ToolDefinition $tool */
            $result[] = $tool->toArray();
        }

        return array_values($result); // Ensure list (numeric keys)
    }

    /**
     * Get all resource definitions formatted as arrays for MCP responses.
     *
     * @return list<array> An array of resource definition arrays.
     */
    public function getResourceDefinitionsAsArray(): array
    {
        $result = [];
        foreach ($this->resources as $resource) {
            /** @var ResourceDefinition $resource */
            $result[] = $resource->toArray();
        }

        return array_values($result);
    }

    /**
     * Get all prompt definitions formatted as arrays for MCP responses.
     *
     * @return list<array> An array of prompt definition arrays.
     */
    public function getPromptDefinitionsAsArray(): array
    {
        $result = [];
        foreach ($this->prompts as $prompt) {
            /** @var PromptDefinition $prompt */
            $result[] = $prompt->toArray();
        }

        return array_values($result);
    }

    /**
     * Get all resource template definitions formatted as arrays for MCP responses.
     *
     * @return list<array> An array of resource template definition arrays.
     */
    public function getResourceTemplateDefinitionsAsArray(): array
    {
        $result = [];
        foreach ($this->resourceTemplates as $template) {
            /** @var ResourceTemplateDefinition $template */
            $result[] = $template->toArray();
        }

        return array_values($result);
    }
}
