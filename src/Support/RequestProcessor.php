<?php

declare(strict_types=1);

namespace PhpMcp\Server\Support;

use JsonException;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\JsonRpc\Messages\Notification;
use PhpMcp\Server\JsonRpc\Messages\Request;
use PhpMcp\Server\JsonRpc\Messages\Response;
use PhpMcp\Server\JsonRpc\Messages\Error;
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
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Support\ArgumentPreparer;
use PhpMcp\Server\Support\SchemaValidator;
use PhpMcp\Server\Traits\ResponseFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;
use Throwable;

/**
 * Central processor for MCP requests, handling both JSON-RPC protocol and MCP methods.
 */
class RequestProcessor
{
    use ResponseFormatter;

    protected const SUPPORTED_PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26'];

    protected LoggerInterface $logger;

    protected ContainerInterface $container;

    protected SchemaValidator $schemaValidator;

    protected ArgumentPreparer $argumentPreparer;

    public function __construct(
        protected Configuration $configuration,
        protected Registry $registry,
        protected SessionManager $sessionManager,
        ?SchemaValidator $schemaValidator = null,
        ?ArgumentPreparer $argumentPreparer = null
    ) {
        $this->container = $configuration->container;
        $this->logger = $configuration->logger;

        $this->schemaValidator = $schemaValidator ?? new SchemaValidator($this->configuration->logger);
        $this->argumentPreparer = $argumentPreparer ?? new ArgumentPreparer($this->configuration->logger);
    }

    public function processNotification(Notification $message, string $sessionId): void
    {
        $method = $message->method;
        $params = $message->params;

        if ($method === 'notifications/initialized') {
            $this->handleNotificationInitialized($params, $sessionId);
        }
    }

    public function processRequest(Request $message, string $sessionId): Response|Error
    {
        $method = $message->method;
        $params = $message->params;

        try {
            /** @var Result|null $result */
            $result = null;

            if ($method === 'initialize') {
                $result = $this->handleInitialize($params, $sessionId);
            } elseif ($method === 'ping') {
                $result = $this->handlePing($sessionId);
            } else {
                $this->validateSessionInitialized($sessionId);
                [$type, $action] = $this->parseMethod($method);
                $this->validateCapabilityEnabled($type);

                $result = match ($type) {
                    'tools' => match ($action) {
                        'list' => $this->handleToolList($params),
                        'call' => $this->handleToolCall($params),
                        default => throw McpServerException::methodNotFound($method),
                    },
                    'resources' => match ($action) {
                        'list' => $this->handleResourcesList($params),
                        'read' => $this->handleResourceRead($params),
                        'subscribe' => $this->handleResourceSubscribe($params, $sessionId),
                        'unsubscribe' => $this->handleResourceUnsubscribe($params, $sessionId),
                        'templates/list' => $this->handleResourceTemplateList($params),
                        default => throw McpServerException::methodNotFound($method),
                    },
                    'prompts' => match ($action) {
                        'list' => $this->handlePromptsList($params),
                        'get' => $this->handlePromptGet($params),
                        default => throw McpServerException::methodNotFound($method),
                    },
                    'logging' => match ($action) {
                        'setLevel' => $this->handleLoggingSetLevel($params, $sessionId),
                        default => throw McpServerException::methodNotFound($method),
                    },
                    default => throw McpServerException::methodNotFound($method),
                };
            }

            if (isset($id) && $result === null && $method !== 'notifications/initialized') {
                $this->logger->error('MCP Processor resulted in null for a request requiring a response', ['method' => $method]);
                throw McpServerException::internalError("Processing method '{$method}' failed to return a result.");
            }

            return Response::make($result, $message->id);
        } catch (McpServerException $e) {
            $this->logger->debug('MCP Processor caught McpServerException', ['method' => $method, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => $e->getData()]);

            return $e->toJsonRpcError($message->id);
        } catch (Throwable $e) {
            $this->logger->error('MCP Processor caught unexpected error', ['method' => $method, 'exception' => $e]);

            return new Error(
                jsonrpc: '2.0',
                id: $message->id,
                code: Error::CODE_INTERNAL_ERROR,
                message: 'Internal error processing method ' . $method,
                data: $e->getMessage()
            );
        }
    }

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

    private function validateSessionInitialized(string $sessionId): void
    {
        if (! $this->sessionManager->isSessionInitialized($sessionId)) {
            throw McpServerException::invalidRequest('Client session not initialized.');
        }
    }

    private function validateCapabilityEnabled(string $type): void
    {
        $caps = $this->configuration->capabilities;

        $enabled = match ($type) {
            'tools' => $caps->toolsEnabled,
            'resources', 'resources/templates' => $caps->resourcesEnabled,
            'resources/subscribe', 'resources/unsubscribe' => $caps->resourcesEnabled && $caps->resourcesSubscribe,
            'prompts' => $caps->promptsEnabled,
            'logging' => $caps->loggingEnabled,
            default => false,
        };

        if (! $enabled) {
            $methodSegment = explode('/', $type)[0];
            throw McpServerException::methodNotFound("MCP capability '{$methodSegment}' is not enabled on this server.");
        }
    }

    private function handleInitialize(array $params, string $sessionId): InitializeResult
    {
        $protocolVersion = $params['protocolVersion'] ?? null;
        if (! $protocolVersion) {
            throw McpServerException::invalidParams("Missing 'protocolVersion' parameter.");
        }

        if (! in_array($protocolVersion, self::SUPPORTED_PROTOCOL_VERSIONS)) {
            $this->logger->warning("Unsupported protocol version: {$protocolVersion}", [
                'supportedVersions' => self::SUPPORTED_PROTOCOL_VERSIONS,
            ]);
        }

        $serverProtocolVersion = self::SUPPORTED_PROTOCOL_VERSIONS[count(self::SUPPORTED_PROTOCOL_VERSIONS) - 1];

        $clientInfo = $params['clientInfo'] ?? null;
        if (! is_array($clientInfo)) {
            throw McpServerException::invalidParams("Missing or invalid 'clientInfo' parameter.");
        }

        $this->sessionManager->storeClientInfo($sessionId, $clientInfo);

        $serverInfo = [
            'name' => $this->configuration->serverName,
            'version' => $this->configuration->serverVersion,
        ];

        $serverCapabilities = $this->configuration->capabilities;
        $responseCapabilities = $serverCapabilities->toInitializeResponseArray();

        $instructions = $serverCapabilities->instructions;

        return new InitializeResult($serverInfo, $serverProtocolVersion, $responseCapabilities, $instructions);
    }

    private function handlePing(string $sessionId): EmptyResult
    {
        return new EmptyResult();
    }

    private function handleNotificationInitialized(array $params, string $sessionId): EmptyResult
    {
        $this->sessionManager->initializeSession($sessionId);

        return new EmptyResult();
    }


    private function handleToolList(array $params): ListToolsResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allTools()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListToolsResult(array_values($pagedItems), $nextCursor);
    }

    private function handleResourcesList(array $params): ListResourcesResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allResources()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListResourcesResult(array_values($pagedItems), $nextCursor);
    }

    private function handleResourceTemplateList(array $params): ListResourceTemplatesResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allResourceTemplates()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListResourceTemplatesResult(array_values($pagedItems), $nextCursor);
    }

    private function handlePromptsList(array $params): ListPromptsResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allPrompts()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListPromptsResult(array_values($pagedItems), $nextCursor);
    }

    private function handleToolCall(array $params): CallToolResult
    {
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? null;

        if (! is_string($toolName) || empty($toolName)) {
            throw McpServerException::invalidParams("Missing or invalid 'name' parameter for tools/call.");
        }

        if ($arguments === null || $arguments === []) {
            $arguments = new stdClass();
        } elseif (! is_array($arguments) && ! $arguments instanceof stdClass) {
            throw McpServerException::invalidParams("Parameter 'arguments' must be an object/array for tools/call.");
        }

        $definition = $this->registry->findTool($toolName);
        if (! $definition) {
            throw McpServerException::methodNotFound("Tool '{$toolName}' not found.");
        }

        $inputSchema = $definition->inputSchema;

        $validationErrors = $this->schemaValidator->validateAgainstJsonSchema($arguments, $inputSchema);

        if (! empty($validationErrors)) {
            $errorMessages = [];

            foreach ($validationErrors as $errorDetail) {
                $pointer = $errorDetail['pointer'] ?? '';
                $message = $errorDetail['message'] ?? 'Unknown validation error';
                $errorMessages[] = ($pointer !== '/' && $pointer !== '' ? "Property '{$pointer}': " : '') . $message;
            }

            $summaryMessage = "Invalid parameters for tool '{$toolName}': " . implode('; ', array_slice($errorMessages, 0, 3));

            if (count($errorMessages) > 3) {
                $summaryMessage .= '; ...and more errors.';
            }

            throw McpServerException::invalidParams($summaryMessage, data: ['validation_errors' => $validationErrors]);
        }

        $argumentsForPhpCall = (array) $arguments;

        try {
            $instance = $this->container->get($definition->className);
            $methodName = $definition->methodName;

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
            $this->logger->warning('MCP SDK: Failed to JSON encode tool result.', ['tool' => $toolName, 'exception' => $e]);
            $errorMessage = "Failed to serialize tool result: {$e->getMessage()}";

            return new CallToolResult([new TextContent($errorMessage)], true);
        } catch (Throwable $toolError) {
            $this->logger->error('MCP SDK: Tool execution failed.', ['tool' => $toolName, 'exception' => $toolError]);
            $errorContent = $this->formatToolErrorResult($toolError);

            return new CallToolResult($errorContent, true);
        }
    }

    private function handleResourceRead(array $params): ReadResourceResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpServerException::invalidParams("Missing or invalid 'uri' parameter for resources/read.");
        }

        $definition = null;
        $uriVariables = [];

        $definition = $this->registry->findResourceByUri($uri);

        if (! $definition) {
            $templateResult = $this->registry->findResourceTemplateByUri($uri);
            if ($templateResult) {
                $definition = $templateResult['definition'];
                $uriVariables = $templateResult['variables'];
            } else {
                throw McpServerException::invalidParams("Resource URI '{$uri}' not found or no handler available.");
            }
        }

        try {
            $instance = $this->container->get($definition->className);
            $methodName = $definition->methodName;

            $methodParams = array_merge($uriVariables, ['uri' => $uri]);

            $args = $this->argumentPreparer->prepareMethodArguments(
                $instance,
                $methodName,
                $methodParams,
                []
            );

            $readResult = $instance->{$methodName}(...$args);
            $contents = $this->formatResourceContents($readResult, $uri, $definition->mimeType);

            return new ReadResourceResult($contents);
        } catch (JsonException $e) {
            $this->logger->warning('MCP SDK: Failed to JSON encode resource content.', ['exception' => $e, 'uri' => $uri]);
            throw McpServerException::internalError("Failed to serialize resource content for '{$uri}'.", $e);
        } catch (McpServerException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('MCP SDK: Resource read failed.', ['uri' => $uri, 'exception' => $e]);
            throw McpServerException::resourceReadFailed($uri, $e);
        }
    }

    private function handleResourceSubscribe(array $params, string $sessionId): EmptyResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpServerException::invalidParams("Missing or invalid 'uri' parameter for resources/subscribe.");
        }

        $this->validateCapabilityEnabled('resources/subscribe');

        $this->sessionManager->addResourceSubscription($sessionId, $uri);

        return new EmptyResult();
    }

    private function handleResourceUnsubscribe(array $params, string $sessionId): EmptyResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpServerException::invalidParams("Missing or invalid 'uri' parameter for resources/unsubscribe.");
        }

        $this->validateCapabilityEnabled('resources/unsubscribe');

        $this->sessionManager->removeResourceSubscription($sessionId, $uri);

        return new EmptyResult();
    }

    private function handlePromptGet(array $params): GetPromptResult
    {
        $promptName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! is_string($promptName) || empty($promptName)) {
            throw McpServerException::invalidParams("Missing or invalid 'name' parameter for prompts/get.");
        }
        if (! is_array($arguments) && ! $arguments instanceof stdClass) {
            throw McpServerException::invalidParams("Parameter 'arguments' must be an object/array for prompts/get.");
        }

        $definition = $this->registry->findPrompt($promptName);
        if (! $definition) {
            throw McpServerException::invalidParams("Prompt '{$promptName}' not found.");
        }

        $arguments = (array) $arguments;

        foreach ($definition->arguments as $argDef) {
            if ($argDef->required && ! array_key_exists($argDef->name, $arguments)) {
                throw McpServerException::invalidParams("Missing required argument '{$argDef->name}' for prompt '{$promptName}'.");
            }
        }

        try {
            $instance = $this->container->get($definition->className);
            $methodName = $definition->methodName;

            $args = $this->argumentPreparer->prepareMethodArguments(
                $instance,
                $methodName,
                $arguments,
                []
            );

            $promptGenerationResult = $instance->{$methodName}(...$args);
            $messages = $this->formatPromptMessages($promptGenerationResult);

            return new GetPromptResult($messages, $definition->description);
        } catch (JsonException $e) {
            $this->logger->warning('MCP SDK: Failed to JSON encode prompt messages.', ['exception' => $e, 'promptName' => $promptName]);
            throw McpServerException::internalError("Failed to serialize prompt messages for '{$promptName}'.", $e);
        } catch (McpServerException $e) {
            throw $e; // Re-throw known MCP errors
        } catch (Throwable $e) {
            $this->logger->error('MCP SDK: Prompt generation failed.', ['promptName' => $promptName, 'exception' => $e]);
            throw McpServerException::promptGenerationFailed($promptName, $e); // Use specific factory
        }
    }

    private function handleLoggingSetLevel(array $params, string $sessionId): EmptyResult
    {
        $level = $params['level'] ?? null;
        $validLevels = [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        ];

        if (! is_string($level) || ! in_array(strtolower($level), $validLevels)) {
            throw McpServerException::invalidParams("Invalid or missing 'level'. Must be one of: " . implode(', ', $validLevels));
        }

        $this->validateCapabilityEnabled('logging');

        $this->sessionManager->setLogLevel($sessionId, strtolower($level));

        $this->logger->info("Log level set to '{$level}'.", ['sessionId' => $sessionId]);

        return new EmptyResult();
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            $this->logger->warning('Received invalid pagination cursor (not base64)', ['cursor' => $cursor]);

            return 0;
        }
        if (preg_match('/^offset=(\d+)$/', $decoded, $matches)) {
            return (int) $matches[1];
        }
        $this->logger->warning('Received invalid pagination cursor format', ['cursor' => $decoded]);

        return 0;
    }

    private function encodeNextCursor(int $currentOffset, int $returnedCount, int $totalCount, int $limit): ?string
    {
        $nextOffset = $currentOffset + $returnedCount;
        if ($returnedCount > 0 && $nextOffset < $totalCount) {
            return base64_encode("offset={$nextOffset}");
        }

        return null;
    }
}
