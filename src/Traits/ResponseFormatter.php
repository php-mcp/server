<?php

namespace PhpMcp\Server\Traits;

use JsonException;
use PhpMcp\Server\JsonRpc\Contents\AudioContent;
use PhpMcp\Server\JsonRpc\Contents\Content;
use PhpMcp\Server\JsonRpc\Contents\EmbeddedResource;
use PhpMcp\Server\JsonRpc\Contents\ImageContent;
use PhpMcp\Server\JsonRpc\Contents\PromptMessage;
use PhpMcp\Server\JsonRpc\Contents\ResourceContent;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\Model\Role;
use Throwable;

/**
 * Trait for formatting raw PHP results into MCP-compliant result structures.
 */
trait ResponseFormatter
{
    /**
     * Formats the result of a successful tool execution into the MCP CallToolResult structure.
     *
     * @param  mixed  $toolExecutionResult  The raw value returned by the tool's PHP method.
     * @return Content[] The content items for CallToolResult.
     *
     * @throws JsonException if JSON encoding fails
     */
    protected function formatToolResult(mixed $toolExecutionResult): array
    {
        if (is_array($toolExecutionResult) && ! empty($toolExecutionResult) && $toolExecutionResult[array_key_first($toolExecutionResult)] instanceof Content) {
            return $toolExecutionResult;
        }

        if ($toolExecutionResult instanceof Content) {
            return [$toolExecutionResult];
        }

        if ($toolExecutionResult === null) {
            if (($outputSchema['type'] ?? 'mixed') !== 'void') {
                return [TextContent::make('(null)')];
            }
            return [];
        }

        if (is_bool($toolExecutionResult)) {
            return [TextContent::make($toolExecutionResult ? 'true' : 'false')];
        }

        if (is_scalar($toolExecutionResult)) {
            return [TextContent::make($toolExecutionResult)];
        }

        $jsonResult = json_encode(
            $toolExecutionResult,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return [TextContent::make($jsonResult)];
    }

    /**
     * Formats an error caught during tool execution into MCP CallToolResult content.
     *
     * @param  Throwable  $toolError  The exception caught from the tool method.
     * @return array<Content> Content objects describing the error.
     */
    protected function formatToolErrorResult(Throwable $toolError): array
    {
        $errorMessage = 'Tool execution failed: ' . $toolError->getMessage();
        $errorMessage .= ' (Type: ' . get_class($toolError) . ')';

        return [
            new TextContent($errorMessage),
        ];
    }

    /**
     * Formats the raw result of a resource read operation into MCP ResourceContent items.
     *
     * @param  mixed  $readResult  The raw result from the resource handler method.
     * @param  string  $uri  The URI of the resource that was read.
     * @param  ?string  $defaultMimeType  The default MIME type from the ResourceDefinition.
     * @return array<EmbeddedResource> Array of EmbeddedResource objects.
     *
     * @throws \RuntimeException If the result cannot be formatted.
     *
     * Supported result types:
     * - EmbeddedResource: Used as-is
     * - ResourceContent: Embedded resource is extracted
     * - string: Converted to text content with guessed or provided MIME type
     * - stream resource: Read and converted to blob with provided MIME type
     * - array with 'blob' key: Used as blob content
     * - array with 'text' key: Used as text content
     * - SplFileInfo: Read and converted to blob
     * - array: Converted to JSON if MIME type is application/json or contains 'json'
     *          For other MIME types, will try to convert to JSON with a warning
     */
    protected function formatResourceContents(mixed $readResult, string $uri, ?string $defaultMimeType): array
    {
        if ($readResult instanceof ResourceContent) {
            return [$readResult];
        }

        if ($readResult instanceof EmbeddedResource) {
            return [$readResult->resource];
        }

        if (is_array($readResult) && ! empty($readResult) && $readResult[array_key_first($readResult)] instanceof ResourceContent) {
            return $readResult;
        }

        if (is_array($readResult) && ! empty($readResult) && $readResult[array_key_first($readResult)] instanceof EmbeddedResource) {
            return array_map(fn ($item) => $item->resource, $readResult);
        }

        if (is_string($readResult)) {
            $mimeType = $defaultMimeType ?? $this->guessMimeTypeFromString($readResult);

            return [new ResourceContent($uri, $mimeType, $readResult)];
        }

        if (is_resource($readResult) && get_resource_type($readResult) === 'stream') {
            $result = ResourceContent::fromStream(
                $uri,
                $readResult,
                $defaultMimeType ?? 'application/octet-stream'
            );

            if (is_resource($readResult)) {
                @fclose($readResult);
            }

            return [$result];
        }

        if (is_array($readResult) && isset($readResult['blob']) && is_string($readResult['blob'])) {
            $mimeType = $readResult['mimeType'] ?? $defaultMimeType ?? 'application/octet-stream';

            return [new ResourceContent($uri, $mimeType, null, $readResult['blob'])];
        }

        if (is_array($readResult) && isset($readResult['text']) && is_string($readResult['text'])) {
            $mimeType = $readResult['mimeType'] ?? $defaultMimeType ?? 'text/plain';

            return [new ResourceContent($uri, $mimeType, $readResult['text'])];
        }

        if ($readResult instanceof \SplFileInfo && $readResult->isFile() && $readResult->isReadable()) {
            return [ResourceContent::fromSplFileInfo($uri, $readResult, $defaultMimeType)];
        }

        if (is_array($readResult)) {
            if ($defaultMimeType && (str_contains(strtolower($defaultMimeType), 'json') ||
                $defaultMimeType === 'application/json')) {
                try {
                    $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                    return [new ResourceContent($uri, $defaultMimeType, $jsonString)];
                } catch (\JsonException $e) {
                    $this->logger->warning('MCP SDK: Failed to JSON encode array resource result', [
                        'uri' => $uri,
                        'exception' => $e->getMessage(),
                    ]);
                    throw new \RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
                }
            }

            try {
                $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                $mimeType = 'application/json';
                $this->logger->warning('MCP SDK: Automatically converted array to JSON for resource', [
                    'uri' => $uri,
                    'requestedMimeType' => $defaultMimeType,
                    'usedMimeType' => $mimeType,
                ]);

                return [new ResourceContent($uri, $mimeType, $jsonString)];
            } catch (\JsonException $e) {
                $this->logger->error('MCP SDK: Failed to encode array resource result as JSON', [
                    'uri' => $uri,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->error('MCP SDK: Unformattable resource read result type.', ['type' => gettype($readResult), 'uri' => $uri]);
        throw new \RuntimeException("Cannot format resource read result for URI '{$uri}'. Handler method returned unhandled type: " . gettype($readResult));
    }

    /** Guesses MIME type from string content (very basic) */
    private function guessMimeTypeFromString(string $content): string
    {
        $trimmed = ltrim($content);
        if (str_starts_with($trimmed, '<') && str_ends_with(rtrim($content), '>')) {
            // Looks like HTML or XML? Prefer text/plain unless sure.
            if (stripos($trimmed, '<html') !== false) {
                return 'text/html';
            }
            if (stripos($trimmed, '<?xml') !== false) {
                return 'application/xml';
            } // or text/xml

            return 'text/plain'; // Default for tag-like structures
        }
        if (str_starts_with($trimmed, '{') && str_ends_with(rtrim($content), '}')) {
            return 'application/json';
        }
        if (str_starts_with($trimmed, '[') && str_ends_with(rtrim($content), ']')) {
            return 'application/json';
        }

        return 'text/plain'; // Default
    }

    /**
     * Formats the raw result of a prompt generator into an array of MCP PromptMessages.
     *
     * @param  mixed  $promptGenerationResult  Expected: array of message structures.
     * @return array<PromptMessage> Array of PromptMessage objects.
     *
     * @throws \RuntimeException If the result cannot be formatted.
     * @throws \JsonException If JSON encoding fails.
     */
    protected function formatPromptMessages(mixed $promptGenerationResult): array
    {
        if (
            is_array($promptGenerationResult) && ! empty($promptGenerationResult)
            && $promptGenerationResult[array_key_first($promptGenerationResult)] instanceof PromptMessage
        ) {
            return $promptGenerationResult;
        }

        if (
            is_array($promptGenerationResult) && ! array_is_list($promptGenerationResult)
            && (isset($promptGenerationResult['user']) || isset($promptGenerationResult['assistant']))
        ) {

            $result = [];
            if (isset($promptGenerationResult['user'])) {
                $result[] = PromptMessage::user($promptGenerationResult['user']);
            }
            if (isset($promptGenerationResult['assistant'])) {
                $result[] = PromptMessage::assistant($promptGenerationResult['assistant']);
            }

            if (! empty($result)) {
                return $result;
            }
        }

        if (! is_array($promptGenerationResult)) {
            throw new \RuntimeException('Prompt generator method must return an array of messages.');
        }

        if (! array_is_list($promptGenerationResult)) {
            throw new \RuntimeException('Prompt generator method must return a list (sequential array) of messages, not an associative array.');
        }

        $formattedMessages = [];
        foreach ($promptGenerationResult as $index => $message) {
            if ($message instanceof PromptMessage) {
                $formattedMessages[] = $message;

                continue;
            }

            if (is_array($message) && isset($message['role']) && isset($message['content'])) {
                $role = $message['role'] instanceof Role ? $message['role'] : Role::tryFrom($message['role']);
                $content = $message['content'];

                if ($role === null) {
                    throw new \RuntimeException("Invalid role '{$message['role']}' in prompt message at index {$index}. Only 'user' or 'assistant' are supported.");
                }

                if ($content instanceof Content) {
                    $formattedMessages[] = new PromptMessage($role, $content);

                    continue;
                }

                if (is_string($content)) {
                    $formattedMessages[] = new PromptMessage($role, new TextContent($content));

                    continue;
                }

                if (is_array($content) && isset($content['type'])) {
                    $type = $content['type'];
                    if (! in_array($type, ['text', 'image', 'audio', 'resource'])) {
                        throw new \RuntimeException("Invalid content type '{$type}' in prompt message at index {$index}.");
                    }

                    $contentObj = null;
                    switch ($type) {
                        case 'text':
                            if (! isset($content['text']) || ! is_string($content['text'])) {
                                throw new \RuntimeException("Invalid 'text' content: Missing or invalid 'text' string.");
                            }
                            $contentObj = new TextContent($content['text']);
                            break;

                        case 'image':
                            if (! isset($content['data']) || ! is_string($content['data'])) {
                                throw new \RuntimeException("Invalid 'image' content: Missing or invalid 'data' string (base64).");
                            }
                            if (! isset($content['mimeType']) || ! is_string($content['mimeType'])) {
                                throw new \RuntimeException("Invalid 'image' content: Missing or invalid 'mimeType' string.");
                            }
                            $contentObj = new ImageContent($content['data'], $content['mimeType']);
                            break;

                        case 'audio':
                            if (! isset($content['data']) || ! is_string($content['data'])) {
                                throw new \RuntimeException("Invalid 'audio' content: Missing or invalid 'data' string (base64).");
                            }
                            if (! isset($content['mimeType']) || ! is_string($content['mimeType'])) {
                                throw new \RuntimeException("Invalid 'audio' content: Missing or invalid 'mimeType' string.");
                            }
                            $contentObj = new AudioContent($content['data'], $content['mimeType']);
                            break;

                        case 'resource':
                            if (! isset($content['resource']) || ! is_array($content['resource'])) {
                                throw new \RuntimeException("Invalid 'resource' content: Missing or invalid 'resource' object.");
                            }

                            $resource = $content['resource'];
                            if (! isset($resource['uri']) || ! is_string($resource['uri'])) {
                                throw new \RuntimeException("Invalid resource: Missing or invalid 'uri'.");
                            }

                            $resourceObj = null;
                            if (isset($resource['text']) && is_string($resource['text'])) {
                                $resourceObj = new ResourceContent($resource['uri'], $resource['mimeType'] ?? 'text/plain', $resource['text']);
                            } elseif (isset($resource['blob']) && is_string($resource['blob'])) {
                                $resourceObj = new ResourceContent(
                                    $resource['uri'],
                                    $resource['mimeType'] ?? 'application/octet-stream',
                                    null,
                                    $resource['blob']
                                );
                            } else {
                                throw new \RuntimeException("Invalid resource: Must contain 'text' or 'blob'.");
                            }

                            $contentObj = new EmbeddedResource($resourceObj);
                            break;
                    }

                    if ($contentObj) {
                        $formattedMessages[] = new PromptMessage($role, $contentObj);

                        continue;
                    }
                }

                throw new \RuntimeException("Invalid content format at index {$index}. Must be a string, Content object, or valid content array.");
            }

            throw new \RuntimeException("Invalid message format at index {$index}. Expected a PromptMessage or an array with 'role' and 'content' keys.");
        }

        return $formattedMessages;
    }
}
