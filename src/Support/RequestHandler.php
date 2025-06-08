<?php

declare(strict_types=1);

namespace PhpMcp\Server\Support;

use JsonException;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\JsonRpc\Results\CallToolResult;
use PhpMcp\Server\JsonRpc\Results\EmptyResult;
use PhpMcp\Server\JsonRpc\Results\GetPromptResult;
use PhpMcp\Server\JsonRpc\Results\InitializeResult;
use PhpMcp\Server\JsonRpc\Results\ListPromptsResult;
use PhpMcp\Server\JsonRpc\Results\ListResourcesResult;
use PhpMcp\Server\JsonRpc\Results\ListResourceTemplatesResult;
use PhpMcp\Server\JsonRpc\Results\ListToolsResult;
use PhpMcp\Server\JsonRpc\Results\ReadResourceResult;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SessionManager;
use PhpMcp\Server\Traits\ResponseFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;
use Throwable;

class RequestHandler
{
    use ResponseFormatter;

    protected ContainerInterface $container;
    protected LoggerInterface $logger;

    public function __construct(
        protected Configuration $configuration,
        protected Registry $registry,
        protected SessionManager $sessionManager,
        protected ?SchemaValidator $schemaValidator = null,
        protected ?ArgumentPreparer $argumentPreparer = null,
    ) {
        $this->container = $this->configuration->container;
        $this->logger = $this->configuration->logger;

        $this->schemaValidator ??= new SchemaValidator($this->logger);
        $this->argumentPreparer ??= new ArgumentPreparer($this->logger);
    }

    public function handleInitialize(array $params, SessionInterface $session): InitializeResult
    {
        $protocolVersion = $params['protocolVersion'] ?? null;
        if (! $protocolVersion) {
            throw McpServerException::invalidParams("Missing 'protocolVersion' parameter.");
        }

        if (! in_array($protocolVersion, Protocol::SUPPORTED_PROTOCOL_VERSIONS)) {
            $this->logger->warning("Unsupported protocol version: {$protocolVersion}", [
                'supportedVersions' => Protocol::SUPPORTED_PROTOCOL_VERSIONS,
            ]);
        }

        $serverProtocolVersion = Protocol::SUPPORTED_PROTOCOL_VERSIONS[count(Protocol::SUPPORTED_PROTOCOL_VERSIONS) - 1];

        $clientInfo = $params['clientInfo'] ?? null;
        if (! is_array($clientInfo)) {
            throw McpServerException::invalidParams("Missing or invalid 'clientInfo' parameter.");
        }

        $session->set('client_info', $clientInfo);

        $serverInfo = [
            'name' => $this->configuration->serverName,
            'version' => $this->configuration->serverVersion,
        ];

        $serverCapabilities = $this->configuration->capabilities;
        $responseCapabilities = $serverCapabilities->toInitializeResponseArray();

        $instructions = $serverCapabilities->instructions;

        return new InitializeResult($serverInfo, $serverProtocolVersion, $responseCapabilities, $instructions);
    }

    public function handlePing(): EmptyResult
    {
        return new EmptyResult();
    }

    public function handleToolList(array $params): ListToolsResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allTools()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListToolsResult(array_values($pagedItems), $nextCursor);
    }

    public function handleResourcesList(array $params): ListResourcesResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allResources()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListResourcesResult(array_values($pagedItems), $nextCursor);
    }

    public function handleResourceTemplateList(array $params): ListResourceTemplatesResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allResourceTemplates()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListResourceTemplatesResult(array_values($pagedItems), $nextCursor);
    }

    public function handlePromptsList(array $params): ListPromptsResult
    {
        $cursor = $params['cursor'] ?? null;
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($cursor);
        $allItems = $this->registry->allPrompts()->getArrayCopy();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListPromptsResult(array_values($pagedItems), $nextCursor);
    }

    public function handleToolCall(array $params): CallToolResult
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

    public function handleResourceRead(array $params): ReadResourceResult
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

    public function handleResourceSubscribe(array $params, SessionInterface $session): EmptyResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpServerException::invalidParams("Missing or invalid 'uri' parameter for resources/subscribe.");
        }

        $subscriptions = $session->get('subscriptions', []);
        $subscriptions[$uri] = true;
        $session->set('subscriptions', $subscriptions);

        return new EmptyResult();
    }

    public function handleResourceUnsubscribe(array $params, SessionInterface $session): EmptyResult
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || empty($uri)) {
            throw McpServerException::invalidParams("Missing or invalid 'uri' parameter for resources/unsubscribe.");
        }

        $subscriptions = $session->get('subscriptions', []);
        unset($subscriptions[$uri]);
        $session->set('subscriptions', $subscriptions);

        return new EmptyResult();
    }

    public function handlePromptGet(array $params): GetPromptResult
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

    public function handleLoggingSetLevel(array $params, SessionInterface $session): EmptyResult
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

        $session->set('log_level', strtolower($level));

        $this->logger->info("Log level set to '{$level}'.", ['sessionId' => $session->getId()]);

        return new EmptyResult();
    }

    public function handleNotificationInitialized(array $params, SessionInterface $session): EmptyResult
    {
        $session->set('initialized', true);

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
