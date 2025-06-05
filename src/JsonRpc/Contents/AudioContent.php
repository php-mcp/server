<?php

namespace PhpMcp\Server\JsonRpc\Contents;

use PhpMcp\Server\Model\Annotations;

/**
 * Represents audio content in MCP.
 */
class AudioContent extends Content
{
    /**
     * Create a new AudioContent instance.
     *
     * @param  string  $data  Base64-encoded audio data
     * @param  string  $mimeType  The MIME type of the audio
     * @param  ?Annotations  $annotations  Optional annotations describing the content
     */
    public function __construct(
        protected string $data,
        protected string $mimeType,
        protected ?Annotations $annotations = null
    ) {}

    /**
     * Get the audio data.
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
     * Get the annotations.
     */
    public function getAnnotations(): ?Annotations
    {
        return $this->annotations;
    }

    /**
     * Get the content type.
     */
    public function getType(): string
    {
        return 'audio';
    }

    /**
     * Convert the content to an array.
     *
     * @return array{type: string, data: string, mimeType: string, annotations?: array}
     */
    public function toArray(): array
    {
        $result = [
            'type' => 'audio',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        if ($this->annotations !== null) {
            $result['annotations'] = $this->annotations->toArray();
        }

        return $result;
    }

    /**
     * Create a new AudioContent from a file path.
     *
     * @param  string  $path  Path to the audio file
     * @param  string|null  $mimeType  Optional MIME type override
     * @param  ?Annotations  $annotations  Optional annotations describing the content
     *
     * @throws \InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $path, ?string $mimeType = null, ?Annotations $annotations = null): static
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("Audio file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read audio file: {$path}");
        }
        $data = base64_encode($content);
        $detectedMime = $mimeType ?? mime_content_type($path) ?: 'application/octet-stream';

        return new static($data, $detectedMime, $annotations);
    }

    /**
     * Create a new AudioContent from raw binary data.
     *
     * @param  string  $binaryData  Raw binary audio data
     * @param  string  $mimeType  MIME type of the audio
     * @param  ?Annotations  $annotations  Optional annotations describing the content
     */
    public static function fromBinary(string $binaryData, string $mimeType, ?Annotations $annotations = null): static
    {
        return new static(base64_encode($binaryData), $mimeType, $annotations);
    }
}
