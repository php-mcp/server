<?php

declare(strict_types=1);

namespace PhpMcp\Server;

use JsonException;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Result;
use PhpMcp\Schema\Notification\InitializedNotification;
use PhpMcp\Schema\Request\CallToolRequest;
use PhpMcp\Schema\Request\CompletionCompleteRequest;
use PhpMcp\Schema\Request\GetPromptRequest;
use PhpMcp\Schema\Request\InitializeRequest;
use PhpMcp\Schema\Request\ListPromptsRequest;
use PhpMcp\Schema\Request\ListResourcesRequest;
use PhpMcp\Schema\Request\ListResourceTemplatesRequest;
use PhpMcp\Schema\Request\ListToolsRequest;
use PhpMcp\Schema\Request\PingRequest;
use PhpMcp\Schema\Request\ReadResourceRequest;
use PhpMcp\Schema\Request\ResourceSubscribeRequest;
use PhpMcp\Schema\Request\ResourceUnsubscribeRequest;
use PhpMcp\Schema\Request\SetLogLevelRequest;
use PhpMcp\Server\Configuration;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Schema\Result\CallToolResult;
use PhpMcp\Schema\Result\CompletionCompleteResult;
use PhpMcp\Schema\Result\EmptyResult;
use PhpMcp\Schema\Result\GetPromptResult;
use PhpMcp\Schema\Result\InitializeResult;
use PhpMcp\Schema\Result\ListPromptsResult;
use PhpMcp\Schema\Result\ListResourcesResult;
use PhpMcp\Schema\Result\ListResourceTemplatesResult;
use PhpMcp\Schema\Result\ListToolsResult;
use PhpMcp\Schema\Result\ReadResourceResult;
use PhpMcp\Server\Protocol;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Session\SubscriptionManager;
use PhpMcp\Server\Support\SchemaValidator;
use PhpMcp\Server\Traits\ResponseFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Dispatcher
{
    use ResponseFormatter;

    protected ContainerInterface $container;
    protected LoggerInterface $logger;

    public function __construct(
        protected Configuration $configuration,
        protected Registry $registry,
        protected SubscriptionManager $subscriptionManager,
        protected ?SchemaValidator $schemaValidator = null,
    ) {
        $this->container = $this->configuration->container;
        $this->logger = $this->configuration->logger;

        $this->schemaValidator ??= new SchemaValidator($this->logger);
    }

    public function handleRequest(Request $request, SessionInterface $session): Result
    {
        switch ($request->method) {
            case 'initialize':
                $request = InitializeRequest::fromRequest($request);
                return $this->handleInitialize($request, $session);
            case 'ping':
                $request = PingRequest::fromRequest($request);
                return $this->handlePing($request);
            case 'tools/list':
                $request = ListToolsRequest::fromRequest($request);
                return $this->handleToolList($request);
            case 'tools/call':
                $request = CallToolRequest::fromRequest($request);
                return $this->handleToolCall($request);
            case 'resources/list':
                $request = ListResourcesRequest::fromRequest($request);
                return $this->handleResourcesList($request);
            case 'resources/templates/list':
                $request = ListResourceTemplatesRequest::fromRequest($request);
                return $this->handleResourceTemplateList($request);
            case 'resources/read':
                $request = ReadResourceRequest::fromRequest($request);
                return $this->handleResourceRead($request);
            case 'resources/subscribe':
                $request = ResourceSubscribeRequest::fromRequest($request);
                return $this->handleResourceSubscribe($request, $session);
            case 'resources/unsubscribe':
                $request = ResourceUnsubscribeRequest::fromRequest($request);
                return $this->handleResourceUnsubscribe($request, $session);
            case 'prompts/list':
                $request = ListPromptsRequest::fromRequest($request);
                return $this->handlePromptsList($request);
            case 'prompts/get':
                $request = GetPromptRequest::fromRequest($request);
                return $this->handlePromptGet($request);
            case 'logging/setLevel':
                $request = SetLogLevelRequest::fromRequest($request);
                return $this->handleLoggingSetLevel($request, $session);
            case 'completion/complete':
                $request = CompletionCompleteRequest::fromRequest($request);
                return $this->handleCompletionComplete($request, $session);
            default:
                throw McpServerException::methodNotFound("Method '{$request->method}' not found.");
        }
    }

    public function handleNotification(Notification $notification, SessionInterface $session): void
    {
        switch ($notification->method) {
            case 'notifications/initialized':
                $notification = InitializedNotification::fromNotification($notification);
                $this->handleNotificationInitialized($notification, $session);
        }
    }

    public function handleInitialize(InitializeRequest $request, SessionInterface $session): InitializeResult
    {
        if (! in_array($request->protocolVersion, Protocol::SUPPORTED_PROTOCOL_VERSIONS)) {
            $this->logger->warning("Unsupported protocol version: {$request->protocolVersion}", [
                'supportedVersions' => Protocol::SUPPORTED_PROTOCOL_VERSIONS,
            ]);
        }

        $protocolVersion = Protocol::LATEST_PROTOCOL_VERSION;

        $session->set('client_info', $request->clientInfo);


        $serverInfo = $this->configuration->serverInfo;
        $capabilities = $this->configuration->capabilities;

        return new InitializeResult($protocolVersion, $capabilities, $serverInfo);
    }

    public function handlePing(PingRequest $request): EmptyResult
    {
        return new EmptyResult();
    }

    public function handleToolList(ListToolsRequest $request): ListToolsResult
    {
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($request->cursor);
        $allItems = $this->registry->getTools();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListToolsResult(array_values($pagedItems), $nextCursor);
    }

    public function handleToolCall(CallToolRequest $request): CallToolResult
    {
        $toolName = $request->name;
        $arguments = $request->arguments;

        ['tool' => $tool, 'handler' => $handler] = $this->registry->getTool($toolName);
        if (! $tool) {
            throw McpServerException::methodNotFound("Tool '{$toolName}' not found.");
        }

        $inputSchema = $tool->inputSchema;

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

        try {
            $result = $handler->handle($this->container, $arguments);
            $formattedResult = $this->formatToolResult($result);

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

    public function handleResourcesList(ListResourcesRequest $request): ListResourcesResult
    {
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($request->cursor);
        $allItems = $this->registry->getResources();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListResourcesResult(array_values($pagedItems), $nextCursor);
    }

    public function handleResourceTemplateList(ListResourceTemplatesRequest $request): ListResourceTemplatesResult
    {
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($request->cursor);
        $allItems = $this->registry->getResourceTemplates();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListResourceTemplatesResult(array_values($pagedItems), $nextCursor);
    }

    public function handleResourceRead(ReadResourceRequest $request): ReadResourceResult
    {
        $uri = $request->uri;

        ['resource' => $resource, 'handler' => $handler, 'variables' => $uriVariables] = $this->registry->getResource($uri);

        if (! $resource) {
            throw McpServerException::invalidParams("Resource URI '{$uri}' not found.");
        }

        try {
            $arguments = array_merge($uriVariables, ['uri' => $uri]);
            $result = $handler->handle($this->container, $arguments);
            $contents = $this->formatResourceContents($result, $uri, $resource->mimeType);

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

    public function handleResourceSubscribe(ResourceSubscribeRequest $request, SessionInterface $session): EmptyResult
    {
        $this->subscriptionManager->subscribe($session->getId(), $request->uri);
        return new EmptyResult();
    }

    public function handleResourceUnsubscribe(ResourceUnsubscribeRequest $request, SessionInterface $session): EmptyResult
    {
        $this->subscriptionManager->unsubscribe($session->getId(), $request->uri);
        return new EmptyResult();
    }

    public function handlePromptsList(ListPromptsRequest $request): ListPromptsResult
    {
        $limit = $this->configuration->paginationLimit;
        $offset = $this->decodeCursor($request->cursor);
        $allItems = $this->registry->getPrompts();
        $pagedItems = array_slice($allItems, $offset, $limit);
        $nextCursor = $this->encodeNextCursor($offset, count($pagedItems), count($allItems), $limit);

        return new ListPromptsResult(array_values($pagedItems), $nextCursor);
    }

    public function handlePromptGet(GetPromptRequest $request): GetPromptResult
    {
        $promptName = $request->name;
        $arguments = $request->arguments;

        ['prompt' => $prompt, 'handler' => $handler] = $this->registry->getPrompt($promptName);
        if (! $prompt) {
            throw McpServerException::invalidParams("Prompt '{$promptName}' not found.");
        }

        $arguments = (array) $arguments;

        foreach ($prompt->arguments as $argDef) {
            if ($argDef->required && ! array_key_exists($argDef->name, $arguments)) {
                throw McpServerException::invalidParams("Missing required argument '{$argDef->name}' for prompt '{$promptName}'.");
            }
        }

        try {
            $result = $handler->handle($this->container, $arguments);
            $messages = $this->formatPromptMessages($result);

            return new GetPromptResult($messages, $prompt->description);
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

    public function handleLoggingSetLevel(SetLogLevelRequest $request, SessionInterface $session): EmptyResult
    {
        $level = $request->level;

        $session->set('log_level', $level->value);

        $this->logger->info("Log level set to '{$level->value}'.", ['sessionId' => $session->getId()]);

        return new EmptyResult();
    }

    public function handleCompletionComplete(CompletionCompleteRequest $request, SessionInterface $session): CompletionCompleteResult
    {
        $ref = $request->ref;
        $argument = $request->argument;

        $completionValues = [];
        $total = null;
        $hasMore = null;

        // TODO: Implement actual completion logic here.
        // This requires a way to:
        // 1. Find the target prompt or resource template definition.
        // 2. Determine if that definition has a completion provider for the given $argName.
        // 3. Invoke that provider with $currentValue and $session (for context).

        // --- Example Logic ---
        if ($argument['name'] === 'userId') {
            $completionValues = ['101', '102', '103'];
            $total = 3;
        }
        // --- End Example ---

        return new CompletionCompleteResult($completionValues, $total, $hasMore);
    }

    public function handleNotificationInitialized(InitializedNotification $notification, SessionInterface $session): EmptyResult
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
