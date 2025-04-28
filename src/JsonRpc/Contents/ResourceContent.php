<?php

namespace PhpMcp\Server\JsonRpc\Contents;

/**
 * Represents embedded resource content in MCP.
 */
class ResourceContent extends Content
{
    /**
     * Create a new ResourceContent instance.
     *
     * @param  EmbeddedResource  $resource  The embedded resource
     */
    public function __construct(
        protected EmbeddedResource $resource
    ) {
    }

    /**
     * Get the resource.
     */
    public function getResource(): EmbeddedResource
    {
        return $this->resource;
    }

    /**
     * Get the content type.
     */
    public function getType(): string
    {
        return 'resource';
    }

    /**
     * Convert the content to an array.
     *
     * @return array{type: string, resource: array}
     */
    public function toArray(): array
    {
        return [
            'type' => 'resource',
            'resource' => $this->resource->toArray(),
        ];
    }

    /**
     * Create a new ResourceContent from a file path.
     *
     * @param  string  $uri  The URI for the resource
     * @param  string  $path  Path to the file
     * @param  string|null  $mimeType  Optional MIME type override
     *
     * @throws \InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $uri, string $path, ?string $mimeType = null): static
    {
        return new static(EmbeddedResource::fromFile($uri, $path, $mimeType));
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
        return new static(EmbeddedResource::fromStream($uri, $stream, $mimeType));
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
        return new static(EmbeddedResource::fromSplFileInfo($uri, $file, $mimeType));
    }

    /**
     * Create a text resource content.
     *
     * @param  string  $uri  The URI for the resource
     * @param  string  $text  The text content
     * @param  string  $mimeType  MIME type of the content
     */
    public static function text(string $uri, string $text, string $mimeType = 'text/plain'): static
    {
        return new static(new EmbeddedResource($uri, $mimeType, $text));
    }

    /**
     * Create a binary resource content.
     *
     * @param  string  $uri  The URI for the resource
     * @param  string  $binaryData  The binary data (will be base64 encoded)
     * @param  string  $mimeType  MIME type of the content
     */
    public static function binary(string $uri, string $binaryData, string $mimeType): static
    {
        return new static(new EmbeddedResource($uri, $mimeType, null, base64_encode($binaryData)));
    }
}
