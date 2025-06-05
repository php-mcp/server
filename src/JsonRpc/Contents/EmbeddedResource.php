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
        public readonly ResourceContent $resource
    ) {}

    /**
     * Convert the resource to an array.
     */
    public function toArray(): array
    {
        $resource = $this->resource->toArray();

        return [
            'type' => 'resource',
            'resource' => $resource,
        ];
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
        return new static(ResourceContent::fromFile($uri, $path, $mimeType));
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
        return new static(ResourceContent::fromStream($uri, $stream, $mimeType));
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
        return new static(ResourceContent::fromSplFileInfo($uri, $file, $mimeType));
    }
}
