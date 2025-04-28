<?php

namespace PhpMcp\Server;

use JsonException;
use PhpMcp\Server\Contracts\ConfigurationRepositoryInterface;
use PhpMcp\Server\Exceptions\McpException;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\JsonRpc\Notification;
use PhpMcp\Server\JsonRpc\Request;
use PhpMcp\Server\JsonRpc\Response;
use PhpMcp\Server\JsonRpc\Result;
use PhpMcp\Server\JsonRpc\Results\CallToolResult;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\JsonRpc\Results\GetPromptResult;
use PhpMcp\Server\JsonRpc\Results\InitializeResult;
use PhpMcp\Server\JsonRpc\Results\ListPromptsResult;
use PhpMcp\Server\JsonRpc\Results\ListResourcesResult;
use PhpMcp\Server\JsonRpc\Results\ListResourceTemplatesResult;
use PhpMcp\Server\JsonRpc\Results\ListToolsResult;
use PhpMcp\Server\JsonRpc\Results\ReadResourceResult;
use PhpMcp\Server\State\TransportState;
use PhpMcp\Server\Support\ArgumentPreparer;
use PhpMcp\Server\Support\SchemaValidator;
use PhpMcp\Server\Traits\ResponseFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Central processor for MCP requests, handling both JSON-RPC protocol and MCP methods.
 */
class Processor
{
    use ResponseFormatter;

    /**
     * Supported protocol versions
     */
    protected array $supportedProtocolVersions = ['2024-11-05'];

    protected SchemaValidator $schemaValidator;

    protected ArgumentPreparer $argumentPreparer;

    /**
     * Create a new MCP processor.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected ConfigurationRepositoryInterface $config,
        protected Registry $registry,
        protected TransportState $transportState,
        protected LoggerInterface $logger,
        ?SchemaValidator $schemaValidator = null,
        ?ArgumentPreparer $argumentPreparer = null
    ) {
        $this->supportedProtocolVersions = $this->config->get('mcp.protocol_versions', ['2024-11-05']);
        $this->registry->loadElements();
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator($this->logger);
        $this->argumentPreparer = $argumentPreparer ?? new ArgumentPreparer($this->logger);
    }

    /**
     * Process a JSON-RPC request.
     *
     * @param  Request|Notification  $request  The JSON-RPC request to process
     * @param  string  $clientId  The client ID associated with this request
     * @return Response|null The JSON-RPC response or null if the request is a notification
     */
    public function process(Request|Notification $message, string $clientId): ?Response
    {
        $method = $message->method;
        $params = $message->params;
        $id = $message instanceof Notification ? null : $message->id;

        try {
            /** @var Result|null $result */
            $result = null;

            if ($method === 'initialize') {
                $result = $this->handleInitialize($params, $clientId);
            } elseif ($method === 'ping') {
                $result = $this->handlePing($clientId);
            } elseif ($method === 'notifications/initialized') {
                $this->handleNotificationInitialized($params, $clientId);

                return null; // Explicitly return null for notifications
            } else {
                // All other methods require initialization
                $this->validateClientInitialized($clientId);
                [$type, $action] = $this->parseMethod($method);
                $this->validateCapabilityEnabled($type); // Check if capability is enabled

                $result = match ($type) {
                    'tools' => match ($action) {
                        'list' => $this->handleToolList($params),
                        'call' => $this->handleToolCall($params),
                        default => throw McpException::methodNotFound($method),
                    },
                    'resources' => match ($action) {
                        'list' => $this->handleResourcesList($params),
                        'read' => $this->handleResourceRead($params),
                        'subscribe' => $this->handleResourceSubscribe($params, $clientId),
                        'unsubscribe' => $this->handleResourceUnsubscribe($params, $clientId),
                        'templates/list' => $this->handleResourceTemplateList($params),
                        default => throw McpException::methodNotFound($method),
                    },
                    'prompts' => match ($action) {
                        'list' => $this->handlePromptsList($params),
                        'get' => $this->handlePromptGet($params),
                        default => throw McpException::methodNotFound($method),
                    },
                    'logging' => match ($action) {
                        'setLevel' => $this->handleLoggingSetLevel($params),
                        default => throw McpException::methodNotFound($method),
                    },
                    default => throw McpException::methodNotFound($method),
                };
            }

            // Only create a response if there's an ID (i.e., it was a Request)
            // Ensure $result is not null for requests that should have results
            if (isset($id) && $result === null && $method !== 'notifications/initialized') {
                $this->logger->error('MCP Processor resulted in null for a request requiring a response', ['method' => $method]);
                throw McpException::internalError("Processing method '{$method}' failed to return a result.");
            }

            return isset($id) ? Response::success($result, id: $id) : null;

        } catch (McpException $e) {
            $this->logger->debug('MCP Processor caught McpError', ['method' => $method, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => $e->getData()]);

            return isset($id) ? Response::error($e->toJsonRpcError(), id: $id) : null;
        } catch (Throwable $e) {
            $this->logger->error('MCP Processor caught unexpected error', ['method' => $method, 'exception' => $e->getMessage()]);

            $mcpError = McpException::methodExecutionFailed($method, $e);

            return isset($id) ? Response::error($mcpError->toJsonRpcError(), id: $id) : null;
        }
    }

    /**
     * Parse method string like "type/action" or "type/nested/action"
     */
    private function parseMethod(string $method): array
    {
        if (str_contains($method, '/')) {
            $parts = explode('/', $method, 2);
            if (count($parts) === 2) {
                return [$parts[0], $parts[1]];
            }
        }

        return [$method, ''];
    }

    /**
     * Validate if the client is initialized.
     *
     * @param  string  $clientId  The client ID
     *
     * @throws McpError If the client is not initialized
     */
    private function validateClientInitialized(string $clientId): void
    {
        if (! $this->transportState->isInitialized($clientId)) {
            throw McpException::invalidRequest('Client not initialized. Please send an initialized notification first.');
        }
    }

    /**
     * Check if a capability type is enabled in the config.
     *
     * @param  string  $type  The capability type (tools, resources, prompts)
     *
     * @throws McpError If the capability is disabled
     */
    private function validateCapabilityEnabled(string $type): void
    {
        $configKey = match ($type) {
            'tools' => 'mcp.capabilities.tools.enabled',
            'resources' => 'mcp.capabilities.resources.enabled',
            'prompts' => 'mcp.capabilities.prompts.enabled',
            'logging' => 'mcp.capabilities.logging.enabled',
            default => null,
        };

        if ($configKey === null) {
            return;
        } // Unknown capability type, assume enabled or let method fail

        if (! $this->config->get($configKey, false)) { // Default to false if not specified
            throw McpException::methodNotFound("MCP capability '{$type}' is not enabled on this server.");
        }
    }

    // --- Handler Implementations ---

    private function handleInitialize(array $params, string $clientId): InitializeResult
    {
        $clientProtocolVersion = $params['protocolVersion'] ?? null;
        if (! $clientProtocolVersion) {
            throw McpException::invalidParams("Missing 'protocolVersion' parameter for initialize request.");
        }

        if (! in_array($clientProtocolVersion, $this->supportedProtocolVersions)) {
            $this->logger->warning("Client requested unsupported protocol version: {$clientProtocolVersion}", [
                'supportedVersions' => $this->supportedProtocolVersions,
            ]);
            // Continue with our preferred version, client should disconnect if it can't support it
        }

        $serverProtocolVersion = $this->config->get(
            'mcp.protocol_version',
            $this->supportedProtocolVersions[count($this->supportedProtocolVersions) - 1]
        );

        $clientInfo = $params['clientInfo'] ?? null;
        if (! is_array($clientInfo)) {
            throw McpException::invalidParams("Missing or invalid 'clientInfo' parameter for initialize request.");
        }

        $this->transportState->storeClientInfo($clientInfo, $serverProtocolVersion, $clientId);

        $serverInfo = [
            'name' => $this->config->get('mcp.server.name', 'PHP MCP Server'),
            'version' => $this->config->get('mcp.server.version', '1.0.0'),
        ];

        $capabilities = [];
        if ($this->config->get('mcp.capabilities.tools.enabled', false) && $this->registry->allTools()->count() > 0) {
            $capabilities['tools'] = ['listChanged' => $this->config->get('mcp.capabilities.tools.listChanged', false)];
        }
        if ($this->config->get('mcp.capabilities.resources.enabled', false) && ($this->registry->allResources()->count() > 0 || $this->registry->allResourceTemplates()->count() > 0)) {
            $cap = [];
            if ($this->config->get('mcp.capabilities.resources.subscribe', false)) {
                $cap['subscribe'] = true;
            }
            if ($this->config->get('mcp.capabilities.resources.listChanged', false)) {
                $cap['listChanged'] = true;
            }
            if (! empty($cap)) {
                $capabilities['resources'] = $cap;
            }
        }
        if ($this->config->get('mcp.capabilities.prompts.enabled', false) && $this->registry->allPrompts()->count() > 0) {
            $capabilities['prompts'] = ['listChanged' => $this->config->get('mcp.capabilities.prompts.listChanged', false)];
        }
        if ($this->config->get('mcp.capabilities.logging.enabled', false)) {
            $capabilities['logging'] = new \stdClass();
        }

        $instructions = $this->config->get('mcp.instructions');

        return new InitializeResult($serverInfo, $serverProtocolVersion, $capabilities, $instructions);
    }

    private function handlePing(string $clientId): EmptyResult
    {
        // Ping response has no specific content, just acknowledges
        return new EmptyResult();
    }

    // --- Notification Handlers ---

    private function handleNotificationInitialized(array $params, string $clientId): EmptyResult
    {
        $this->transportState->markInitialized($clientId);

        return new EmptyResult();
    }

    // --- Tool Handlers ---

    private function handleToolList(array $params): ListToolsResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->config->get('mcp.pagination_limit', 50);
        $offset = $this->decodeCursor($cursor);

        $allToolsArray = $this->registry->allTools()->getArrayCopy();
        $pagedTools = array_slice($allToolsArray, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedTools), count($allToolsArray), $limit);

        return new ListToolsResult(array_values($pagedTools), $nextCursor);
    }

    private function handleToolCall(array $params): CallToolResult
    {
        $toolName = $params['name'] ?? null;
        $argumentsRaw = $params['arguments'] ?? null;

        if (! is_string($toolName) || empty($toolName)) {
            throw McpException::invalidParams("Missing or invalid 'name' parameter for tools/call.");
        }

        if ($argumentsRaw !== null && ! is_array($argumentsRaw)) {
            throw McpException::invalidParams("Parameter 'arguments' must be an object or null for tools/call.");
        }

        if (empty($argumentsRaw)) {
            $argumentsRaw = new stdClass();
        }

        $definition = $this->registry->findTool($toolName);
        if (! $definition) {
            throw McpException::methodNotFound("Tool '{$toolName}' not found.");
        }

        $inputSchema = $definition->getInputSchema();

        $argumentsForValidation = $argumentsRaw;
        $validationErrors = $this->schemaValidator->validateAgainstJsonSchema($argumentsForValidation, $inputSchema);

        if (! empty($validationErrors)) {
            throw McpException::invalidParams(data: ['validation_errors' => $validationErrors]);
        }

        $argumentsForPhpCall = (array) ($argumentsRaw ?? []);

        try {
            $instance = $this->container->get($definition->getClassName());
            $methodName = $definition->getMethodName();

            $args = $this->argumentPreparer->prepareMethodArguments(
                $instance,
                $methodName,
                $argumentsForPhpCall,
                $inputSchema
            );

            $toolExecutionResult = $instance->{$methodName}(...$args);

            $formattedResult = $this->formatToolResult($toolExecutionResult);

            return new CallToolResult($formattedResult, false);
        } catch (JsonException $e) {
            $this->logger->warning('MCP SDK: Failed to JSON encode tool result.', ['exception' => $e]);
            $errorMessage = "Failed to serialize tool result: {$e->getMessage()}";

            return new CallToolResult([new TextContent($errorMessage)], true);
        } catch (Throwable $toolError) {
            $this->logger->error('MCP SDK: Tool execution failed.', ['exception' => $toolError->getMessage()]);
            $errorContent = $this->formatToolErrorResult($toolError);

            return new CallToolResult($errorContent, true);
        }
    }

    // --- Resource Handlers ---

    private function handleResourcesList(array $params): ListResourcesResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->config->get('mcp.pagination_limit', 50);
        $offset = $this->decodeCursor($cursor);

        $allResourcesArray = $this->registry->allResources()->getArrayCopy();
        $pagedResources = array_slice($allResourcesArray, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedResources), count($allResourcesArray), $limit);

        return new ListResourcesResult(array_values($pagedResources), $nextCursor);
    }

    private function handleResourceTemplateList(array $params): ListResourceTemplatesResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->config->get('mcp.pagination_limit', 50);
        $offset = $this->decodeCursor($cursor);

        $allTemplatesArray = $this->registry->allResourceTemplates()->getArrayCopy();
        $pagedTemplates = array_slice($allTemplatesArray, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedTemplates), count($allTemplatesArray), $limit);

        return new ListResourceTemplatesResult(array_values($pagedTemplates), $nextCursor);
    }

    private function handleResourceRead(array $params): ReadResourceResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpException::invalidParams("Missing or invalid 'uri' parameter for resources/read.");
        }

        // First try exact resource match
        $definition = $this->registry->findResourceByUri($uri);
        $uriVariables = [];

        // If no exact match, try template matching
        if (! $definition) {
            $templateResult = $this->registry->findResourceTemplateByUri($uri);
            if (! $templateResult) {
                throw McpException::invalidParams("Resource URI '{$uri}' not found or no handler available.");
            }

            $definition = $templateResult['definition'];
            $uriVariables = $templateResult['variables'];
        }

        try {
            $instance = $this->container->get($definition->getClassName());

            $methodParams = array_merge($uriVariables, ['uri' => $uri]);

            $args = $this->argumentPreparer->prepareMethodArguments(
                $instance,
                $definition->getMethodName(),
                $methodParams,
                []
            );

            $readResult = $instance->{$definition->getMethodName()}(...$args);

            $contents = $this->formatResourceContents($readResult, $uri, $definition->getMimeType());

            return new ReadResourceResult($contents);
        } catch (JsonException $e) {
            $this->logger->warning('MCP SDK: Failed to JSON encode resource content.', ['exception' => $e, 'uri' => $uri]);

            throw McpException::internalError("Failed to serialize resource content for '{$uri}': {$e->getMessage()}", $e);
        } catch (McpException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('MCP SDK: Resource read failed.', ['exception' => $e->getMessage()]);

            throw McpException::internalError("Failed to read resource '{$uri}': {$e->getMessage()}", $e);
        }
    }

    private function handleResourceSubscribe(array $params, string $clientId): EmptyResult
    {
        $uri = $params['uri'] ?? null;

        if (! is_string($uri) || empty($uri)) {
            throw McpException::invalidParams("Missing or invalid 'uri' parameter for resources/subscribe.");
        }
        if (! $this->config->get('mcp.capabilities.resources.subscribe', false)) {
            throw McpException::methodNotFound('Resource subscription is not supported by this server.');
        }

        $this->transportState->addResourceSubscription($clientId, $uri);

        return new EmptyResult();
    }

    private function handleResourceUnsubscribe(array $params, string $clientId): EmptyResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpException::invalidParams("Missing or invalid 'uri' parameter for resources/unsubscribe.");
        }

        $this->transportState->removeResourceSubscription($clientId, $uri);

        return new EmptyResult();
    }

    // --- Prompt Handlers ---

    private function handlePromptsList(array $params): ListPromptsResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->config->get('mcp.pagination_limit', 50);
        $offset = $this->decodeCursor($cursor);

        $allPromptsArray = $this->registry->allPrompts()->getArrayCopy();
        $pagedPrompts = array_slice($allPromptsArray, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedPrompts), count($allPromptsArray), $limit);

        return new ListPromptsResult(array_values($pagedPrompts), $nextCursor);
    }

    private function handlePromptGet(array $params): GetPromptResult
    {
        $promptName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? []; // Arguments for templating

        if (! is_string($promptName) || empty($promptName)) {
            throw McpException::invalidParams("Missing or invalid 'name' parameter for prompts/get.");
        }
        if (! is_array($arguments)) {
            throw McpException::invalidParams("Parameter 'arguments' must be an object for prompts/get.");
        }

        $definition = $this->registry->findPrompt($promptName);
        if (! $definition) {
            throw McpException::invalidParams("Prompt '{$promptName}' not found.");
        }

        // Validate provided arguments against PromptDefinition arguments (required check)
        foreach ($definition->getArguments() as $argDef) {
            if ($argDef->isRequired() && ! array_key_exists($argDef->getName(), $arguments)) {
                throw McpException::invalidParams("Missing required argument '{$argDef->getName()}' for prompt '{$promptName}'.");
            }
        }

        try {
            $instance = $this->container->get($definition->getClassName());
            $methodName = $definition->getMethodName();

            // Prepare arguments for the prompt generator method (likely just the template vars)
            $args = $this->argumentPreparer->prepareMethodArguments(
                $instance,
                $methodName,
                $arguments, // Pass template arguments
                [] // Schema not directly applicable here? Or parse args into schema? Pass empty for now.
            );

            // Execute the prompt generator method
            $promptGenerationResult = $instance->{$methodName}(...$args);

            $messages = $this->formatPromptMessages($promptGenerationResult);

            return new GetPromptResult(
                $messages,
                $definition->getDescription()
            );
        } catch (JsonException $e) {
            $this->logger->warning('MCP SDK: Failed to JSON encode prompt messages.', ['exception' => $e, 'promptName' => $promptName]);

            throw McpException::internalError("Failed to serialize prompt messages for '{$promptName}': {$e->getMessage()}", $e);
        } catch (McpException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('MCP SDK: Prompt generation failed.', ['exception' => $e->getMessage()]);

            throw McpException::internalError("Failed to generate prompt '{$promptName}': {$e->getMessage()}", $e);
        }
    }

    // --- Logging Handlers ---
    private function handleLoggingSetLevel(array $params): EmptyResult
    {
        $level = $params['level'] ?? null;
        $validLevels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        if (! is_string($level) || ! in_array(strtolower($level), $validLevels)) {
            throw McpException::invalidParams("Invalid or missing 'level' parameter. Must be one of: ".implode(', ', $validLevels));
        }

        // Store the requested log level (e.g., in session, config, or state manager)
        // This level should then be checked by the logging notification sender.
        $this->logger->info('MCP logging level set request: '.$level);
        $this->config->set('mcp.runtime.log_level', strtolower($level)); // Example: Store in runtime config

        return new EmptyResult(); // Success is empty result
    }

    // --- Pagination Helpers ---

    /** Decodes the opaque cursor string into an offset */
    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            $this->logger->warning('Received invalid pagination cursor (not base64)', ['cursor' => $cursor]);

            return 0; // Treat invalid cursor as start
        }
        // Expect format "offset=N"
        if (preg_match('/^offset=(\d+)$/', $decoded, $matches)) {
            return (int) $matches[1];
        }
        $this->logger->warning('Received invalid pagination cursor format', ['cursor' => $decoded]);

        return 0; // Treat invalid format as start
    }

    /** Encodes the next cursor string if more items exist */
    private function encodeNextCursor(int $currentOffset, int $returnedCount, int $totalCount, int $limit): ?string
    {
        $nextOffset = $currentOffset + $returnedCount;
        if ($returnedCount > 0 && $nextOffset < $totalCount) {
            return base64_encode("offset={$nextOffset}");
        }

        return null; // No more items
    }
}
