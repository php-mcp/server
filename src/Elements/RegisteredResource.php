<?php

declare(strict_types=1);

namespace PhpMcp\Server\Elements;

use PhpMcp\Schema\Content\BlobResourceContents;
use PhpMcp\Schema\Content\EmbeddedResource;
use PhpMcp\Schema\Content\ResourceContents;
use PhpMcp\Schema\Content\TextResourceContents;
use PhpMcp\Schema\Resource;
use Psr\Container\ContainerInterface;

class RegisteredResource extends RegisteredElement
{
    public function __construct(
        public readonly Resource $schema,
        string $handlerClass,
        string $handlerMethod,
        bool $isManual = false,
    ) {
        parent::__construct($handlerClass, $handlerMethod, $isManual);
    }

    public static function make(Resource $schema, string $handlerClass, string $handlerMethod, bool $isManual = false): self
    {
        return new self($schema, $handlerClass, $handlerMethod, $isManual);
    }

    /**
     * Reads the resource content.
     * 
     * @return array<TextResourceContents|BlobResourceContents> Array of ResourceContents objects.
     */
    public function read(ContainerInterface $container, string $uri): array
    {
        $result = $this->handle($container, ['uri' => $uri]);

        return $this->formatResult($result, $uri, $this->schema->mimeType);
    }

    /**
     * Formats the raw result of a resource read operation into MCP ResourceContent items.
     *
     * @param  mixed  $readResult  The raw result from the resource handler method.
     * @param  string  $uri  The URI of the resource that was read.
     * @param  ?string  $defaultMimeType  The default MIME type from the ResourceDefinition.
     * @return array<TextResourceContents|BlobResourceContents> Array of ResourceContents objects.
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
    protected function formatResult(mixed $readResult, string $uri, ?string $defaultMimeType): array
    {
        if ($readResult instanceof ResourceContents) {
            return [$readResult];
        }

        if ($readResult instanceof EmbeddedResource) {
            return [$readResult->resource];
        }

        if (is_array($readResult) && ! empty($readResult) && $readResult[array_key_first($readResult)] instanceof ResourceContents) {
            return $readResult;
        }

        if (is_array($readResult) && ! empty($readResult) && $readResult[array_key_first($readResult)] instanceof EmbeddedResource) {
            return array_map(fn($item) => $item->resource, $readResult);
        }

        if (is_string($readResult)) {
            $mimeType = $defaultMimeType ?? $this->guessMimeTypeFromString($readResult);

            return [TextResourceContents::make($uri, $mimeType, $readResult)];
        }

        if (is_resource($readResult) && get_resource_type($readResult) === 'stream') {
            $result = BlobResourceContents::fromStream(
                $uri,
                $readResult,
                $defaultMimeType ?? 'application/octet-stream'
            );

            @fclose($readResult);

            return [$result];
        }

        if (is_array($readResult) && isset($readResult['blob']) && is_string($readResult['blob'])) {
            $mimeType = $readResult['mimeType'] ?? $defaultMimeType ?? 'application/octet-stream';

            return [BlobResourceContents::make($uri, $mimeType, $readResult['blob'])];
        }

        if (is_array($readResult) && isset($readResult['text']) && is_string($readResult['text'])) {
            $mimeType = $readResult['mimeType'] ?? $defaultMimeType ?? 'text/plain';

            return [TextResourceContents::make($uri, $mimeType, $readResult['text'])];
        }

        if ($readResult instanceof \SplFileInfo && $readResult->isFile() && $readResult->isReadable()) {
            return [BlobResourceContents::fromSplFileInfo($uri, $readResult, $defaultMimeType)];
        }

        if (is_array($readResult)) {
            if ($defaultMimeType && (str_contains(strtolower($defaultMimeType), 'json') ||
                $defaultMimeType === 'application/json')) {
                try {
                    $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                    return [TextResourceContents::make($uri, $defaultMimeType, $jsonString)];
                } catch (\JsonException $e) {
                    throw new \RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
                }
            }

            try {
                $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                $mimeType = 'application/json';

                return [TextResourceContents::make($uri, $mimeType, $jsonString)];
            } catch (\JsonException $e) {
                throw new \RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
            }
        }

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
}
