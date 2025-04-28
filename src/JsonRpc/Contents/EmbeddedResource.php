<?php

namespace PhpMcp\Server\JsonRpc\Contents;

/**
 * Represents embedded resource content within a message.
 */
class EmbeddedResource
{
    /**
     * Create a new Resource instance.
     *
     * @param  string  $uri  The URI of the resource
     * @param  string  $mimeType  The MIME type of the resource
     * @param  string|null  $text  The text content if available
     * @param  string|null  $blob  Base64-encoded binary data if available
     */
    public function __construct(
        protected string $uri,
        protected string $mimeType,
        protected ?string $text = null,
        protected ?string $blob = null
    ) {
        // Validate that either text or blob is provided, but not both
        if (($text === null && $blob === null) || ($text !== null && $blob !== null)) {
            throw new \InvalidArgumentException('Either text OR blob must be provided for a resource.');
        }
    }

    /**
     * Get the URI.
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get the MIME type.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Get the text content.
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Get the binary data.
     */
    public function getBlob(): ?string
    {
        return $this->blob;
    }

    /**
     * Check if the resource has text content.
     */
    public function hasText(): bool
    {
        return $this->text !== null;
    }

    /**
     * Check if the resource has binary content.
     */
    public function hasBlob(): bool
    {
        return $this->blob !== null;
    }

    /**
     * Convert the resource to an array.
     */
    public function toArray(): array
    {
        $resource = [
            'uri' => $this->uri,
            'mimeType' => $this->mimeType,
        ];

        if ($this->text !== null) {
            $resource['text'] = $this->text;
        } elseif ($this->blob !== null) {
            $resource['blob'] = $this->blob;
        }

        return $resource;
    }

    /**
     * Determines if the given MIME type is likely to be text-based.
     *
     * @param  string  $mimeType  The MIME type to check
     */
    private static function isTextMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/') ||
               in_array($mimeType, ['application/json', 'application/xml', 'application/javascript', 'application/yaml']);
    }

    /**
     * Create a new EmbeddedResource from a file path.
     *
     * @param  string  $uri  The URI for the resource
     * @param  string  $path  Path to the file
     * @param  string|null  $mimeType  Optional MIME type override
     *
     * @throws \InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $uri, string $path, ?string $mimeType = null): static
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $detectedMime = $mimeType ?? mime_content_type($path) ?? 'application/octet-stream';
        $content = file_get_contents($path);

        // Decide if we should use text or blob based on the mime type
        if (self::isTextMimeType($detectedMime)) {
            return new static($uri, $detectedMime, $content);
        } else {
            return new static($uri, $detectedMime, null, base64_encode($content));
        }
    }

    /**
     * Create from a stream resource.
     *
     * @param  string  $uri  The URI for the resource
     * @param  resource  $stream  The stream resource
     * @param  string  $mimeType  MIME type of the content
     *
     * @throws \InvalidArgumentException If the parameter is not a stream resource
     */
    public static function fromStream(string $uri, $stream, string $mimeType): static
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected a stream resource');
        }

        $content = stream_get_contents($stream);

        // Determine if this is text based on mime type
        if (self::isTextMimeType($mimeType)) {
            return new static($uri, $mimeType, $content);
        } else {
            return new static($uri, $mimeType, null, base64_encode($content));
        }
    }

    /**
     * Create from an SplFileInfo object.
     *
     * @param  string  $uri  The URI for the resource
     * @param  \SplFileInfo  $file  The file object
     * @param  string|null  $mimeType  Optional MIME type override
     *
     * @throws \InvalidArgumentException If the file is not readable
     */
    public static function fromSplFileInfo(string $uri, \SplFileInfo $file, ?string $mimeType = null): static
    {
        if (! $file->isReadable()) {
            throw new \InvalidArgumentException("File is not readable: {$file->getPathname()}");
        }

        return self::fromFile($uri, $file->getPathname(), $mimeType);
    }
}
