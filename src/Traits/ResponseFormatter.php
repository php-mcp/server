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
        // If already an array of Content objects, use as is
        if (is_array($toolExecutionResult) && ! empty($toolExecutionResult) && $toolExecutionResult[array_key_first($toolExecutionResult)] instanceof Content) {
            return $toolExecutionResult;
        }

        // If a single Content object, wrap in array
        if ($toolExecutionResult instanceof Content) {
            return [$toolExecutionResult];
        }

        // Null or "void" function
        if ($toolExecutionResult === null) {
            if (($outputSchema['type'] ?? 'mixed') !== 'void') {
                return [TextContent::make('(null)')];
            }
            return [];
        }

        // Handle booleans explicitly
        if (is_bool($toolExecutionResult)) {
            return [TextContent::make($toolExecutionResult ? 'true' : 'false')];
        }

        if (is_scalar($toolExecutionResult)) {
            return [TextContent::make($toolExecutionResult)];
        }

        // Default: JSON encode complex structures - let exceptions bubble up
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
        // Provide a user/LLM-friendly error message. Avoid stack traces.
        $errorMessage = 'Tool execution failed: '.$toolError->getMessage();
        // Include exception type name for context, might help debugging/LLM understanding.
        $errorMessage .= ' (Type: '.get_class($toolError).')';

        return [
            new TextContent($errorMessage),
        ];
    }

    /**
     * Formats the raw result of a resource read operation into MCP ResourceContents items.
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
        // If already an EmbeddedResource, just wrap it
        if ($readResult instanceof EmbeddedResource) {
            return [$readResult];
        }

        // If it's a ResourceContent, extract the embedded resource
        if ($readResult instanceof ResourceContent) {
            return [$readResult->getResource()];
        }

        // Handle array of EmbeddedResource objects
        if (is_array($readResult) && ! empty($readResult) && $readResult[array_key_first($readResult)] instanceof EmbeddedResource) {
            return $readResult;
        }

        // Handle array of ResourceContent objects
        if (is_array($readResult) && ! empty($readResult) && $readResult[array_key_first($readResult)] instanceof ResourceContent) {
            return array_map(fn ($item) => $item->getResource(), $readResult);
        }

        // Handle string (text content)
        if (is_string($readResult)) {
            $mimeType = $defaultMimeType ?? $this->guessMimeTypeFromString($readResult);

            return [new EmbeddedResource($uri, $mimeType, $readResult)];
        }

        // Handle stream resources
        if (is_resource($readResult) && get_resource_type($readResult) === 'stream') {
            // Let exceptions bubble up
            $result = EmbeddedResource::fromStream(
                $uri,
                $readResult,
                $defaultMimeType ?? 'application/octet-stream'
            );

            // Ensure stream is closed if we opened/read it
            if (is_resource($readResult)) {
                @fclose($readResult);
            }

            return [$result];
        }

        // Handle pre-formatted array structure
        if (is_array($readResult) && isset($readResult['blob']) && is_string($readResult['blob'])) {
            $mimeType = $readResult['mimeType'] ?? $defaultMimeType ?? 'application/octet-stream';

            return [new EmbeddedResource($uri, $mimeType, null, $readResult['blob'])];
        }

        if (is_array($readResult) && isset($readResult['text']) && is_string($readResult['text'])) {
            $mimeType = $readResult['mimeType'] ?? $defaultMimeType ?? 'text/plain';

            return [new EmbeddedResource($uri, $mimeType, $readResult['text'])];
        }

        // Handle SplFileInfo
        if ($readResult instanceof \SplFileInfo && $readResult->isFile() && $readResult->isReadable()) {
            // Let exceptions bubble up
            return [EmbeddedResource::fromSplFileInfo($uri, $readResult, $defaultMimeType)];
        }

        // Handle arrays for JSON MIME types - convert to JSON string
        if (is_array($readResult)) {
            // If default MIME type is JSON or contains 'json', encode the array to JSON
            if ($defaultMimeType && (str_contains(strtolower($defaultMimeType), 'json') ||
                                     $defaultMimeType === 'application/json')) {
                try {
                    $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                    return [new EmbeddedResource($uri, $defaultMimeType, $jsonString)];
                } catch (\JsonException $e) {
                    $this->logger->warning('MCP SDK: Failed to JSON encode array resource result', [
                        'uri' => $uri,
                        'exception' => $e->getMessage(),
                    ]);
                    throw new \RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
                }
            }

            // For non-JSON mime types, we could still try to encode the array, but with a warning
            try {
                $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                $mimeType = 'application/json'; // Override to JSON mime type
                $this->logger->warning('MCP SDK: Automatically converted array to JSON for resource', [
                    'uri' => $uri,
                    'requestedMimeType' => $defaultMimeType,
                    'usedMimeType' => $mimeType,
                ]);

                return [new EmbeddedResource($uri, $mimeType, $jsonString)];
            } catch (\JsonException $e) {
                // If JSON encoding fails, log error and continue to the error handling below
                $this->logger->error('MCP SDK: Failed to encode array resource result as JSON', [
                    'uri' => $uri,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->error('MCP SDK: Unformattable resource read result type.', ['type' => gettype($readResult), 'uri' => $uri]);
        throw new \RuntimeException("Cannot format resource read result for URI '{$uri}'. Handler method returned unhandled type: ".gettype($readResult));
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
        // If already an array of PromptMessage objects, use as is
        if (is_array($promptGenerationResult) && ! empty($promptGenerationResult)
            && $promptGenerationResult[array_key_first($promptGenerationResult)] instanceof PromptMessage) {
            return $promptGenerationResult;
        }

        // Handle simple role => text pairs array
        if (is_array($promptGenerationResult) && ! array_is_list($promptGenerationResult)
            && (isset($promptGenerationResult['user']) || isset($promptGenerationResult['assistant']))) {

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

        // Ensure it's a list of messages
        if (! array_is_list($promptGenerationResult)) {
            throw new \RuntimeException('Prompt generator method must return a list (sequential array) of messages, not an associative array.');
        }

        $formattedMessages = [];
        foreach ($promptGenerationResult as $index => $message) {
            // If it's already a PromptMessage, use it directly
            if ($message instanceof PromptMessage) {
                $formattedMessages[] = $message;

                continue;
            }

            // Handle simple role => content object
            if (is_array($message) && isset($message['role']) && isset($message['content']) && count($message) === 2) {
                $role = $message['role'];
                $content = $message['content'];

                if (! in_array($role, ['user', 'assistant'])) {
                    throw new \RuntimeException("Invalid role '{$role}' in prompt message at index {$index}. Only 'user' or 'assistant' are supported.");
                }

                // If content is already a Content object
                if ($content instanceof Content) {
                    $formattedMessages[] = new PromptMessage($role, $content);

                    continue;
                }

                // If content is a string, convert to TextContent
                if (is_string($content)) {
                    $formattedMessages[] = new PromptMessage($role, new TextContent($content));

                    continue;
                }

                // Handle content array with type field
                if (is_array($content) && isset($content['type'])) {
                    $type = $content['type'];
                    if (! in_array($type, ['text', 'image', 'audio', 'resource'])) {
                        throw new \RuntimeException("Invalid content type '{$type}' in prompt message at index {$index}.");
                    }

                    // Convert to appropriate Content object
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

                            $embeddedResource = null;
                            if (isset($resource['text']) && is_string($resource['text'])) {
                                $embeddedResource = new EmbeddedResource(
                                    $resource['uri'],
                                    $resource['mimeType'] ?? 'text/plain',
                                    $resource['text']
                                );
                            } elseif (isset($resource['blob']) && is_string($resource['blob'])) {
                                $embeddedResource = new EmbeddedResource(
                                    $resource['uri'],
                                    $resource['mimeType'] ?? 'application/octet-stream',
                                    null,
                                    $resource['blob']
                                );
                            } else {
                                throw new \RuntimeException("Invalid resource: Must contain 'text' or 'blob'.");
                            }

                            $contentObj = new ResourceContent($embeddedResource);
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
