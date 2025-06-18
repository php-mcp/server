<?php

namespace PhpMcp\Server\Tests\Fixtures\General;

use PhpMcp\Schema\Content\EmbeddedResource;
use PhpMcp\Schema\Content\TextResourceContents;
use PhpMcp\Schema\Content\BlobResourceContents;
use Psr\Log\LoggerInterface;
use SplFileInfo;

class ResourceHandlerFixture
{
    public static string $staticTextContent = "Default static text content.";
    public array $dynamicContentStore = [];
    public static ?string $unlinkableSplFile = null;

    public function __construct()
    {
        $this->dynamicContentStore['dynamic://data/item1'] = "Content for item 1";
    }

    public function returnStringText(string $uri): string
    {
        return "Plain string content for {$uri}";
    }

    public function returnStringJson(string $uri): string
    {
        return json_encode(['uri_in_json' => $uri, 'data' => 'some json string']);
    }

    public function returnStringHtml(string $uri): string
    {
        return "<html><title>{$uri}</title><body>Content</body></html>";
    }

    public function returnArrayJson(string $uri): array
    {
        return ['uri_in_array' => $uri, 'message' => 'This is JSON data from array', 'timestamp' => time()];
    }

    public function returnEmptyArray(string $uri): array
    {
        return [];
    }

    public function returnStream(string $uri) // Returns a stream resource
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "Streamed content for {$uri}");
        rewind($stream);
        return $stream;
    }

    public function returnSplFileInfo(string $uri): SplFileInfo
    {
        self::$unlinkableSplFile = tempnam(sys_get_temp_dir(), 'res_fixture_spl_');
        file_put_contents(self::$unlinkableSplFile, "Content from SplFileInfo for {$uri}");
        return new SplFileInfo(self::$unlinkableSplFile);
    }

    public function returnEmbeddedResource(string $uri): EmbeddedResource
    {
        return EmbeddedResource::make(
            TextResourceContents::make($uri, 'application/vnd.custom-embedded', 'Direct EmbeddedResource content')
        );
    }

    public function returnTextResourceContents(string $uri): TextResourceContents
    {
        return TextResourceContents::make($uri, 'text/special-contents', 'Direct TextResourceContents');
    }

    public function returnBlobResourceContents(string $uri): BlobResourceContents
    {
        return BlobResourceContents::make($uri, 'application/custom-blob-contents', base64_encode('blobbycontents'));
    }

    public function returnArrayForBlobSchema(string $uri): array
    {
        return ['blob' => base64_encode("Blob for {$uri} via array"), 'mimeType' => 'application/x-custom-blob-array'];
    }

    public function returnArrayForTextSchema(string $uri): array
    {
        return ['text' => "Text from array for {$uri} via array", 'mimeType' => 'text/vnd.custom-array-text'];
    }

    public function returnArrayOfResourceContents(string $uri): array
    {
        return [
            TextResourceContents::make($uri . "_part1", 'text/plain', 'Part 1 of many RC'),
            BlobResourceContents::make($uri . "_part2", 'image/png', base64_encode('pngdata')),
        ];
    }

    public function returnArrayOfEmbeddedResources(string $uri): array
    {
        return [
            EmbeddedResource::make(TextResourceContents::make($uri . "_emb1", 'text/xml', '<doc1/>')),
            EmbeddedResource::make(BlobResourceContents::make($uri . "_emb2", 'font/woff2', base64_encode('fontdata'))),
        ];
    }

    public function returnMixedArrayWithResourceTypes(string $uri): array
    {
        return [
            "A raw string piece", // Will be formatted
            TextResourceContents::make($uri . "_rc1", 'text/markdown', '**Markdown!**'), // Used as is
            ['nested_array_data' => 'value', 'for_uri' => $uri], // Will be formatted (JSON)
            EmbeddedResource::make(TextResourceContents::make($uri . "_emb1", 'text/csv', 'col1,col2')), // Extracted
        ];
    }

    public function handlerThrowsException(string $uri): void
    {
        throw new \DomainException("Cannot read resource {$uri} - handler error.");
    }

    public function returnUnformattableType(string $uri)
    {
        return new \DateTimeImmutable();
    }

    public function resourceHandlerNeedsUri(string $uri): string
    {
        return "Handler received URI: " . $uri;
    }

    public function resourceHandlerDoesNotNeedUri(): string
    {
        return "Handler did not need or receive URI parameter.";
    }

    public function getTemplatedContent(
        string $category,
        string $itemId,
        string $format,
    ): array {
        return [
            'message' => "Content for item {$itemId} in category {$category}, format {$format}.",
            'category_received' => $category,
            'itemId_received' => $itemId,
            'format_received' => $format,
        ];
    }

    public function getStaticText(): string
    {
        return self::$staticTextContent;
    }
}
