<?php

declare(strict_types=1);

namespace PhpMcp\Server\Elements;

use PhpMcp\Schema\Content\BlobResourceContents;
use PhpMcp\Schema\Content\EmbeddedResource;
use PhpMcp\Schema\Content\ResourceContents;
use PhpMcp\Schema\Content\TextResourceContents;
use PhpMcp\Schema\Resource;
use Psr\Container\ContainerInterface;
use Throwable;

class RegisteredResource extends RegisteredElement
{
    public function __construct(
        public readonly Resource $schema,
        callable|array|string $handler,
        bool $isManual = false,
    ) {
        parent::__construct($handler, $isManual);
    }

    public static function make(Resource $schema, callable|array|string $handler, bool $isManual = false): self
    {
        return new self($schema, $handler, $isManual);
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
     * @param  ?string  $mimeType  The MIME type from the ResourceDefinition.
     * @return array<TextResourceContents|BlobResourceContents> Array of ResourceContents objects.
     *
     * @throws \RuntimeException If the result cannot be formatted.
     *
     * Supported result types:
     * - ResourceContent: Used as-is
     * - EmbeddedResource: Resource is extracted from the EmbeddedResource
     * - string: Converted to text content with guessed or provided MIME type
     * - stream resource: Read and converted to blob with provided MIME type
     * - array with 'blob' key: Used as blob content
     * - array with 'text' key: Used as text content
     * - SplFileInfo: Read and converted to blob
     * - array: Converted to JSON if MIME type is application/json or contains 'json'
     *          For other MIME types, will try to convert to JSON with a warning
     */
    protected function formatResult(mixed $readResult, string $uri, ?string $mimeType): array
    {
        if ($readResult instanceof ResourceContents) {
            return [$readResult];
        }

        if ($readResult instanceof EmbeddedResource) {
            return [$readResult->resource];
        }

        if (is_array($readResult)) {
            if (empty($readResult)) {
                return [TextResourceContents::make($uri, 'application/json', '[]')];
            }

            $allAreResourceContents = true;
            $hasResourceContents = false;
            $allAreEmbeddedResource = true;
            $hasEmbeddedResource = false;

            foreach ($readResult as $item) {
                if ($item instanceof ResourceContents) {
                    $hasResourceContents = true;
                    $allAreEmbeddedResource = false;
                } elseif ($item instanceof EmbeddedResource) {
                    $hasEmbeddedResource = true;
                    $allAreResourceContents = false;
                } else {
                    $allAreResourceContents = false;
                    $allAreEmbeddedResource = false;
                }
            }

            if ($allAreResourceContents && $hasResourceContents) {
                return $readResult;
            }

            if ($allAreEmbeddedResource && $hasEmbeddedResource) {
                return array_map(fn($item) => $item->resource, $readResult);
            }

            if ($hasResourceContents || $hasEmbeddedResource) {
                $result = [];
                foreach ($readResult as $item) {
                    if ($item instanceof ResourceContents) {
                        $result[] = $item;
                    } elseif ($item instanceof EmbeddedResource) {
                        $result[] = $item->resource;
                    } else {
                        $result = array_merge($result, $this->formatResult($item, $uri, $mimeType));
                    }
                }
                return $result;
            }
        }

        if (is_string($readResult)) {
            $mimeType = $mimeType ?? $this->guessMimeTypeFromString($readResult);

            return [TextResourceContents::make($uri, $mimeType, $readResult)];
        }

        if (is_resource($readResult) && get_resource_type($readResult) === 'stream') {
            $result = BlobResourceContents::fromStream(
                $uri,
                $readResult,
                $mimeType ?? 'application/octet-stream'
            );

            @fclose($readResult);

            return [$result];
        }

        if (is_array($readResult) && isset($readResult['blob']) && is_string($readResult['blob'])) {
            $mimeType = $readResult['mimeType'] ?? $mimeType ?? 'application/octet-stream';

            return [BlobResourceContents::make($uri, $mimeType, $readResult['blob'])];
        }

        if (is_array($readResult) && isset($readResult['text']) && is_string($readResult['text'])) {
            $mimeType = $readResult['mimeType'] ?? $mimeType ?? 'text/plain';

            return [TextResourceContents::make($uri, $mimeType, $readResult['text'])];
        }

        if ($readResult instanceof \SplFileInfo && $readResult->isFile() && $readResult->isReadable()) {
            if ($mimeType && str_contains(strtolower($mimeType), 'text')) {
                return [TextResourceContents::make($uri, $mimeType, file_get_contents($readResult->getPathname()))];
            }

            return [BlobResourceContents::fromSplFileInfo($uri, $readResult, $mimeType)];
        }

        if (is_array($readResult)) {
            if ($mimeType && (str_contains(strtolower($mimeType), 'json') ||
                $mimeType === 'application/json')) {
                try {
                    $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                    return [TextResourceContents::make($uri, $mimeType, $jsonString)];
                } catch (\JsonException $e) {
                    throw new \RuntimeException("Failed to encode array as JSON for URI '{$uri}': {$e->getMessage()}");
                }
            }

            try {
                $jsonString = json_encode($readResult, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                $mimeType = $mimeType ?? 'application/json';

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
            if (str_contains($trimmed, '<html')) {
                return 'text/html';
            }
            if (str_contains($trimmed, '<?xml')) {
                return 'application/xml';
            }

            return 'text/plain';
        }

        if (str_starts_with($trimmed, '{') && str_ends_with(rtrim($content), '}')) {
            return 'application/json';
        }

        if (str_starts_with($trimmed, '[') && str_ends_with(rtrim($content), ']')) {
            return 'application/json';
        }

        return 'text/plain';
    }

    public function toArray(): array
    {
        return [
            'schema' => $this->schema->toArray(),
            ...parent::toArray(),
        ];
    }

    public static function fromArray(array $data): self|false
    {
        try {
            if (! isset($data['schema']) || ! isset($data['handler'])) {
                return false;
            }

            return new self(
                Resource::fromArray($data['schema']),
                $data['handler'],
                $data['isManual'] ?? false,
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}
