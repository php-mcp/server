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
use PhpMcp\Schema\Result\CompletionCompleteResult;
use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

class RegisteredPrompt extends RegisteredElement
{
    public function __construct(
        public readonly Prompt $schema,
        callable|array|string $handler,
        bool $isManual = false,
        public readonly array $completionProviders = []
    ) {
        parent::__construct($handler, $isManual);
    }

    public static function make(Prompt $schema, callable|array|string $handler, bool $isManual = false, array $completionProviders = []): self
    {
        return new self($schema, $handler, $isManual, $completionProviders);
    }

    /**
     * Gets the prompt messages.
     *
     * @param  ContainerInterface  $container
     * @param  array  $arguments
     * @return PromptMessage[]
     */
    public function get(ContainerInterface $container, array $arguments): array
    {
        $result = $this->handle($container, $arguments);

        return $this->formatResult($result);
    }

    public function complete(ContainerInterface $container, string $argument, string $value, SessionInterface $session): CompletionCompleteResult
    {
        $providerClassOrInstance = $this->completionProviders[$argument] ?? null;
        if ($providerClassOrInstance === null) {
            return new CompletionCompleteResult([]);
        }

        if (is_string($providerClassOrInstance)) {
            if (! class_exists($providerClassOrInstance)) {
                throw new \RuntimeException("Completion provider class '{$providerClassOrInstance}' does not exist.");
            }

            $provider = $container->get($providerClassOrInstance);
        } else {
            $provider = $providerClassOrInstance;
        }

        $completions = $provider->getCompletions($value, $session);

        $total = count($completions);
        $hasMore = $total > 100;

        $pagedCompletions = array_slice($completions, 0, 100);

        return new CompletionCompleteResult($pagedCompletions, $total, $hasMore);
    }

    /**
     * Formats the raw result of a prompt generator into an array of MCP PromptMessages.
     *
     * @param  mixed  $promptGenerationResult  Expected: array of message structures.
     * @return PromptMessage[] Array of PromptMessage objects.
     *
     * @throws \RuntimeException If the result cannot be formatted.
     * @throws \JsonException If JSON encoding fails.
     */
    protected function formatResult(mixed $promptGenerationResult): array
    {
        if ($promptGenerationResult instanceof PromptMessage) {
            return [$promptGenerationResult];
        }

        if (! is_array($promptGenerationResult)) {
            throw new \RuntimeException('Prompt generator method must return an array of messages.');
        }

        if (empty($promptGenerationResult)) {
            return [];
        }

        if (is_array($promptGenerationResult)) {
            $allArePromptMessages = true;
            $hasPromptMessages = false;

            foreach ($promptGenerationResult as $item) {
                if ($item instanceof PromptMessage) {
                    $hasPromptMessages = true;
                } else {
                    $allArePromptMessages = false;
                }
            }

            if ($allArePromptMessages && $hasPromptMessages) {
                return $promptGenerationResult;
            }

            if ($hasPromptMessages) {
                $result = [];
                foreach ($promptGenerationResult as $index => $item) {
                    if ($item instanceof PromptMessage) {
                        $result[] = $item;
                    } else {
                        $result = array_merge($result, $this->formatResult($item));
                    }
                }
                return $result;
            }

            if (! array_is_list($promptGenerationResult)) {
                if (isset($promptGenerationResult['user']) || isset($promptGenerationResult['assistant'])) {
                    $result = [];
                    if (isset($promptGenerationResult['user'])) {
                        $userContent = $this->formatContent($promptGenerationResult['user']);
                        $result[] = PromptMessage::make(Role::User, $userContent);
                    }
                    if (isset($promptGenerationResult['assistant'])) {
                        $assistantContent = $this->formatContent($promptGenerationResult['assistant']);
                        $result[] = PromptMessage::make(Role::Assistant, $assistantContent);
                    }
                    return $result;
                }

                if (isset($promptGenerationResult['role']) && isset($promptGenerationResult['content'])) {
                    return [$this->formatMessage($promptGenerationResult)];
                }

                throw new \RuntimeException('Associative array must contain either role/content keys or user/assistant keys.');
            }

            $formattedMessages = [];
            foreach ($promptGenerationResult as $index => $message) {
                if ($message instanceof PromptMessage) {
                    $formattedMessages[] = $message;
                } else {
                    $formattedMessages[] = $this->formatMessage($message, $index);
                }
            }
            return $formattedMessages;
        }

        throw new \RuntimeException('Invalid prompt generation result format.');
    }

    /**
     * Formats a single message into a PromptMessage.
     */
    private function formatMessage(mixed $message, ?int $index = null): PromptMessage
    {
        $indexStr = $index !== null ? " at index {$index}" : '';

        if (! is_array($message) || ! array_key_exists('role', $message) || ! array_key_exists('content', $message)) {
            throw new \RuntimeException("Invalid message format{$indexStr}. Expected an array with 'role' and 'content' keys.");
        }

        $role = $message['role'] instanceof Role ? $message['role'] : Role::tryFrom($message['role']);
        if ($role === null) {
            throw new \RuntimeException("Invalid role '{$message['role']}' in prompt message{$indexStr}. Only 'user' or 'assistant' are supported.");
        }

        $content = $this->formatContent($message['content'], $index);

        return new PromptMessage($role, $content);
    }

    /**
     * Formats content into a proper Content object.
     */
    private function formatContent(mixed $content, ?int $index = null): TextContent|ImageContent|AudioContent|EmbeddedResource
    {
        $indexStr = $index !== null ? " at index {$index}" : '';

        if ($content instanceof Content) {
            if (
                $content instanceof TextContent || $content instanceof ImageContent ||
                $content instanceof AudioContent || $content instanceof EmbeddedResource
            ) {
                return $content;
            }
            throw new \RuntimeException("Invalid Content type{$indexStr}. PromptMessage only supports TextContent, ImageContent, AudioContent, or EmbeddedResource.");
        }

        if (is_string($content)) {
            return TextContent::make($content);
        }

        if (is_array($content) && isset($content['type'])) {
            return $this->formatTypedContent($content, $index);
        }

        if (is_scalar($content) || $content === null) {
            $stringContent = $content === null ? '(null)' : (is_bool($content) ? ($content ? 'true' : 'false') : (string)$content);
            return TextContent::make($stringContent);
        }

        $jsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return TextContent::make($jsonContent);
    }

    /**
     * Formats typed content arrays into Content objects.
     */
    private function formatTypedContent(array $content, ?int $index = null): TextContent|ImageContent|AudioContent|EmbeddedResource
    {
        $indexStr = $index !== null ? " at index {$index}" : '';
        $type = $content['type'];

        return match ($type) {
            'text' => $this->formatTextContent($content, $indexStr),
            'image' => $this->formatImageContent($content, $indexStr),
            'audio' => $this->formatAudioContent($content, $indexStr),
            'resource' => $this->formatResourceContent($content, $indexStr),
            default => throw new \RuntimeException("Invalid content type '{$type}'{$indexStr}.")
        };
    }

    private function formatTextContent(array $content, string $indexStr): TextContent
    {
        if (! isset($content['text']) || ! is_string($content['text'])) {
            throw new \RuntimeException("Invalid 'text' content{$indexStr}: Missing or invalid 'text' string.");
        }
        return TextContent::make($content['text']);
    }

    private function formatImageContent(array $content, string $indexStr): ImageContent
    {
        if (! isset($content['data']) || ! is_string($content['data'])) {
            throw new \RuntimeException("Invalid 'image' content{$indexStr}: Missing or invalid 'data' string (base64).");
        }
        if (! isset($content['mimeType']) || ! is_string($content['mimeType'])) {
            throw new \RuntimeException("Invalid 'image' content{$indexStr}: Missing or invalid 'mimeType' string.");
        }
        return ImageContent::make($content['data'], $content['mimeType']);
    }

    private function formatAudioContent(array $content, string $indexStr): AudioContent
    {
        if (! isset($content['data']) || ! is_string($content['data'])) {
            throw new \RuntimeException("Invalid 'audio' content{$indexStr}: Missing or invalid 'data' string (base64).");
        }
        if (! isset($content['mimeType']) || ! is_string($content['mimeType'])) {
            throw new \RuntimeException("Invalid 'audio' content{$indexStr}: Missing or invalid 'mimeType' string.");
        }
        return AudioContent::make($content['data'], $content['mimeType']);
    }

    private function formatResourceContent(array $content, string $indexStr): EmbeddedResource
    {
        if (! isset($content['resource']) || ! is_array($content['resource'])) {
            throw new \RuntimeException("Invalid 'resource' content{$indexStr}: Missing or invalid 'resource' object.");
        }

        $resource = $content['resource'];
        if (! isset($resource['uri']) || ! is_string($resource['uri'])) {
            throw new \RuntimeException("Invalid resource{$indexStr}: Missing or invalid 'uri'.");
        }

        if (isset($resource['text']) && is_string($resource['text'])) {
            $resourceObj = TextResourceContents::make($resource['uri'], $resource['mimeType'] ?? 'text/plain', $resource['text']);
        } elseif (isset($resource['blob']) && is_string($resource['blob'])) {
            $resourceObj = BlobResourceContents::make(
                $resource['uri'],
                $resource['mimeType'] ?? 'application/octet-stream',
                $resource['blob']
            );
        } else {
            throw new \RuntimeException("Invalid resource{$indexStr}: Must contain 'text' or 'blob'.");
        }

        return new EmbeddedResource($resourceObj);
    }

    public function toArray(): array
    {
        $completionProviders = [];
        foreach ($this->completionProviders as $argument => $provider) {
            $completionProviders[$argument] = serialize($provider);
        }

        return [
            'schema' => $this->schema->toArray(),
            'completionProviders' => $completionProviders,
            ...parent::toArray(),
        ];
    }

    public static function fromArray(array $data): self|false
    {
        try {
            if (! isset($data['schema']) || ! isset($data['handler'])) {
                return false;
            }

            $completionProviders = [];
            foreach ($data['completionProviders'] ?? [] as $argument => $provider) {
                $completionProviders[$argument] = unserialize($provider);
            }

            return new self(
                Prompt::fromArray($data['schema']),
                $data['handler'],
                $data['isManual'] ?? false,
                $completionProviders,
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}
