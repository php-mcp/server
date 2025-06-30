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
use PhpMcp\Schema\Content\TextContent;
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
use PhpMcp\Server\Utils\SchemaValidator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Dispatcher
{
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
        if (in_array($request->protocolVersion, Protocol::SUPPORTED_PROTOCOL_VERSIONS)) {
            $protocolVersion = $request->protocolVersion;
        } else {
            $protocolVersion = Protocol::LATEST_PROTOCOL_VERSION;
        }

        $session->set('client_info', $request->clientInfo->toArray());
        $session->set('protocol_version', $protocolVersion);

        $serverInfo = $this->configuration->serverInfo;
        $capabilities = $this->configuration->capabilities;
        $instructions = $this->configuration->instructions;

        return new InitializeResult($protocolVersion, $capabilities, $serverInfo, $instructions);
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

        $registeredTool = $this->registry->getTool($toolName);
        if (! $registeredTool) {
            throw McpServerException::methodNotFound("Tool '{$toolName}' not found.");
        }

        $inputSchema = $registeredTool->schema->inputSchema;

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
            $result = $registeredTool->call($this->container, $arguments);

            return new CallToolResult($result, false);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to JSON encode tool result.', ['tool' => $toolName, 'exception' => $e]);
            $errorMessage = "Failed to serialize tool result: {$e->getMessage()}";

            return new CallToolResult([new TextContent($errorMessage)], true);
        } catch (Throwable $toolError) {
            $this->logger->error('Tool execution failed.', ['tool' => $toolName, 'exception' => $toolError]);
            $errorMessage = "Tool execution failed: {$toolError->getMessage()}";

            return new CallToolResult([new TextContent($errorMessage)], true);
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

        $registeredResource = $this->registry->getResource($uri);

        if (! $registeredResource) {
            throw McpServerException::invalidParams("Resource URI '{$uri}' not found.");
        }

        try {
            $result = $registeredResource->read($this->container, $uri);

            return new ReadResourceResult($result);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to JSON encode resource content.', ['exception' => $e, 'uri' => $uri]);
            throw McpServerException::internalError("Failed to serialize resource content for '{$uri}'.", $e);
        } catch (McpServerException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Resource read failed.', ['uri' => $uri, 'exception' => $e]);
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

        $registeredPrompt = $this->registry->getPrompt($promptName);
        if (! $registeredPrompt) {
            throw McpServerException::invalidParams("Prompt '{$promptName}' not found.");
        }

        $arguments = (array) $arguments;

        foreach ($registeredPrompt->schema->arguments as $argDef) {
            if ($argDef->required && ! array_key_exists($argDef->name, $arguments)) {
                throw McpServerException::invalidParams("Missing required argument '{$argDef->name}' for prompt '{$promptName}'.");
            }
        }

        try {
            $result = $registeredPrompt->get($this->container, $arguments);

            return new GetPromptResult($result, $registeredPrompt->schema->description);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to JSON encode prompt messages.', ['exception' => $e, 'promptName' => $promptName]);
            throw McpServerException::internalError("Failed to serialize prompt messages for '{$promptName}'.", $e);
        } catch (McpServerException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Prompt generation failed.', ['promptName' => $promptName, 'exception' => $e]);
            throw McpServerException::promptGenerationFailed($promptName, $e);
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
        $argumentName = $request->argument['name'];
        $currentValue = $request->argument['value'];

        $identifier = null;

        if ($ref->type === 'ref/prompt') {
            $identifier = $ref->name;
            $registeredPrompt = $this->registry->getPrompt($identifier);
            if (! $registeredPrompt) {
                throw McpServerException::invalidParams("Prompt '{$identifier}' not found.");
            }

            $foundArg = false;
            foreach ($registeredPrompt->schema->arguments as $arg) {
                if ($arg->name === $argumentName) {
                    $foundArg = true;
                    break;
                }
            }
            if (! $foundArg) {
                throw McpServerException::invalidParams("Argument '{$argumentName}' not found in prompt '{$identifier}'.");
            }

            return $registeredPrompt->complete($this->container, $argumentName, $currentValue, $session);
        } elseif ($ref->type === 'ref/resource') {
            $identifier = $ref->uri;
            $registeredResourceTemplate = $this->registry->getResourceTemplate($identifier);
            if (! $registeredResourceTemplate) {
                throw McpServerException::invalidParams("Resource template '{$identifier}' not found.");
            }

            $foundArg = false;
            foreach ($registeredResourceTemplate->getVariableNames() as $uriVariableName) {
                if ($uriVariableName === $argumentName) {
                    $foundArg = true;
                    break;
                }
            }

            if (! $foundArg) {
                throw McpServerException::invalidParams("URI variable '{$argumentName}' not found in resource template '{$identifier}'.");
            }

            return $registeredResourceTemplate->complete($this->container, $argumentName, $currentValue, $session);
        } else {
            throw McpServerException::invalidParams("Invalid ref type '{$ref->type}' for completion complete request.");
        }
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
