<?php

declare(strict_types=1);

namespace PhpMcp\Server\Elements;

use PhpMcp\Schema\Content\AudioContent;
use PhpMcp\Schema\Content\BlobResourceContents;
use PhpMcp\Schema\Content\Content;
use PhpMcp\Schema\Content\EmbeddedResource;
use PhpMcp\Schema\Content\ImageContent;
use PhpMcp\Schema\Prompt;
use PhpMcp\Schema\Content\PromptMessage;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\TextResourceContents;
use PhpMcp\Schema\Enum\Role;
use Psr\Container\ContainerInterface;

class RegisteredPrompt extends RegisteredElement
{
    public function __construct(
        public readonly Prompt $schema,
        string $handlerClass,
        string $handlerMethod,
        bool $isManual = false,
        public readonly array $completionProviders = []
    ) {
        parent::__construct($handlerClass, $handlerMethod, $isManual);
    }

    public static function make(Prompt $schema, string $handlerClass, string $handlerMethod, bool $isManual = false, array $completionProviders = []): self
    {
        return new self($schema, $handlerClass, $handlerMethod, $isManual, $completionProviders);
    }

    /**
     * Gets the prompt messages.
     */
    public function get(ContainerInterface $container, array $arguments): array
    {
        $result = $this->handle($container, $arguments);

        return $this->formatResult($result);
    }

    public function getCompletionProvider(string $argumentName): ?string
    {
        return $this->completionProviders[$argumentName] ?? null;
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
    protected function formatResult(mixed $promptGenerationResult): array
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
                $result[] = PromptMessage::make(Role::User, $promptGenerationResult['user']);
            }
            if (isset($promptGenerationResult['assistant'])) {
                $result[] = PromptMessage::make(Role::Assistant, $promptGenerationResult['assistant']);
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
                            $contentObj = TextContent::make($content['text']);
                            break;

                        case 'image':
                            if (! isset($content['data']) || ! is_string($content['data'])) {
                                throw new \RuntimeException("Invalid 'image' content: Missing or invalid 'data' string (base64).");
                            }
                            if (! isset($content['mimeType']) || ! is_string($content['mimeType'])) {
                                throw new \RuntimeException("Invalid 'image' content: Missing or invalid 'mimeType' string.");
                            }
                            $contentObj = ImageContent::make($content['data'], $content['mimeType']);
                            break;

                        case 'audio':
                            if (! isset($content['data']) || ! is_string($content['data'])) {
                                throw new \RuntimeException("Invalid 'audio' content: Missing or invalid 'data' string (base64).");
                            }
                            if (! isset($content['mimeType']) || ! is_string($content['mimeType'])) {
                                throw new \RuntimeException("Invalid 'audio' content: Missing or invalid 'mimeType' string.");
                            }
                            $contentObj = AudioContent::make($content['data'], $content['mimeType']);
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
                                $resourceObj = TextResourceContents::make($resource['uri'], $resource['mimeType'] ?? 'text/plain', $resource['text']);
                            } elseif (isset($resource['blob']) && is_string($resource['blob'])) {
                                $resourceObj = BlobResourceContents::make(
                                    $resource['uri'],
                                    $resource['mimeType'] ?? 'application/octet-stream',
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
