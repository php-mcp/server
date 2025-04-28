<?php

namespace PhpMcp\Server\JsonRpc\Contents;

/**
 * Represents image content in MCP.
 */
class ImageContent extends Content
{
    /**
     * Create a new ImageContent instance.
     *
     * @param  string  $data  Base64-encoded image data
     * @param  string  $mimeType  The MIME type of the image
     */
    public function __construct(
        protected string $data,
        protected string $mimeType
    ) {
    }

    /**
     * Get the image data.
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * Get the MIME type.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Get the content type.
     */
    public function getType(): string
    {
        return 'image';
    }

    /**
     * Convert the content to an array.
     *
     * @return array{type: string, data: string, mimeType: string}
     */
    public function toArray(): array
    {
        return [
            'type' => 'image',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];
    }

    /**
     * Create a new ImageContent from a file path.
     *
     * @param  string  $path  Path to the image file
     * @param  string|null  $mimeType  Optional MIME type override
     *
     * @throws \InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $path, ?string $mimeType = null): static
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("Image file not found: {$path}");
        }

        $data = base64_encode(file_get_contents($path));
        $detectedMime = $mimeType ?? mime_content_type($path) ?? 'image/png';

        return new static($data, $detectedMime);
    }

    /**
     * Create a new ImageContent from raw binary data.
     *
     * @param  string  $binaryData  Raw binary image data
     * @param  string  $mimeType  MIME type of the image
     */
    public static function fromBinary(string $binaryData, string $mimeType): static
    {
        return new static(base64_encode($binaryData), $mimeType);
    }
}
