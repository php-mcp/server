<?php

namespace PhpMcp\Server\Tests\Unit\Elements;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpMcp\Schema\Annotations;
use PhpMcp\Schema\Resource as ResourceSchema;
use PhpMcp\Server\Elements\RegisteredResource;
use PhpMcp\Schema\Content\TextResourceContents;
use PhpMcp\Schema\Content\BlobResourceContents;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use Psr\Container\ContainerInterface;
use PhpMcp\Server\Exception\McpServerException;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->handlerInstance = new ResourceHandlerFixture();
    $this->container->shouldReceive('get')
        ->with(ResourceHandlerFixture::class)
        ->andReturn($this->handlerInstance)
        ->byDefault();

    $this->testUri = 'test://resource/item.txt';
    $this->resourceSchema = ResourceSchema::make($this->testUri, 'test-resource', mimeType: 'text/plain');
    $this->registeredResource = RegisteredResource::make(
        $this->resourceSchema,
        [ResourceHandlerFixture::class, 'returnStringText']
    );
});

afterEach(function () {
    if (ResourceHandlerFixture::$unlinkableSplFile && file_exists(ResourceHandlerFixture::$unlinkableSplFile)) {
        @unlink(ResourceHandlerFixture::$unlinkableSplFile);
        ResourceHandlerFixture::$unlinkableSplFile = null;
    }
});

it('constructs correctly and exposes schema', function () {
    expect($this->registeredResource->schema)->toBe($this->resourceSchema);
    expect($this->registeredResource->handler)->toBe([ResourceHandlerFixture::class, 'returnStringText']);
    expect($this->registeredResource->isManual)->toBeFalse();
});

it('can be made as a manual registration', function () {
    $manualResource = RegisteredResource::make($this->resourceSchema, [ResourceHandlerFixture::class, 'returnStringText'], true);
    expect($manualResource->isManual)->toBeTrue();
});

it('passes URI to handler if handler method expects it', function () {
    $resource = RegisteredResource::make(
        ResourceSchema::make($this->testUri, 'needs-uri'),
        [ResourceHandlerFixture::class, 'resourceHandlerNeedsUri']
    );

    $handlerMock = Mockery::mock(ResourceHandlerFixture::class);
    $handlerMock->shouldReceive('resourceHandlerNeedsUri')
        ->with($this->testUri)
        ->once()
        ->andReturn("Confirmed URI: {$this->testUri}");
    $this->container->shouldReceive('get')->with(ResourceHandlerFixture::class)->andReturn($handlerMock);

    $result = $resource->read($this->container, $this->testUri);
    expect($result[0]->text)->toBe("Confirmed URI: {$this->testUri}");
});

it('does not require handler method to accept URI', function () {
    $resource = RegisteredResource::make(
        ResourceSchema::make($this->testUri, 'no-uri-param'),
        [ResourceHandlerFixture::class, 'resourceHandlerDoesNotNeedUri']
    );
    $handlerMock = Mockery::mock(ResourceHandlerFixture::class);
    $handlerMock->shouldReceive('resourceHandlerDoesNotNeedUri')->once()->andReturn("Success no URI");
    $this->container->shouldReceive('get')->with(ResourceHandlerFixture::class)->andReturn($handlerMock);

    $result = $resource->read($this->container, $this->testUri);
    expect($result[0]->text)->toBe("Success no URI");
});


dataset('resource_handler_return_types', [
    'string_text'        => ['returnStringText', 'text/plain', fn($text, $uri) => expect($text)->toBe("Plain string content for {$uri}"), null],
    'string_json_guess'  => ['returnStringJson', 'application/json', fn($text, $uri) => expect(json_decode($text, true)['uri_in_json'])->toBe($uri), null],
    'string_html_guess'  => ['returnStringHtml', 'text/html', fn($text, $uri) => expect($text)->toContain("<title>{$uri}</title>"), null],
    'array_json_schema_mime' => ['returnArrayJson', 'application/json', fn($text, $uri) => expect(json_decode($text, true)['uri_in_array'])->toBe($uri), null], // schema has text/plain, overridden by array + JSON content
    'empty_array'        => ['returnEmptyArray', 'application/json', fn($text) => expect($text)->toBe('[]'), null],
    'stream_octet'       => ['returnStream', 'application/octet-stream', null, fn($blob, $uri) => expect(base64_decode($blob ?? ''))->toBe("Streamed content for {$uri}")],
    'array_for_blob'     => ['returnArrayForBlobSchema', 'application/x-custom-blob-array', null, fn($blob, $uri) => expect(base64_decode($blob ?? ''))->toBe("Blob for {$uri} via array")],
    'array_for_text'     => ['returnArrayForTextSchema', 'text/vnd.custom-array-text', fn($text, $uri) => expect($text)->toBe("Text from array for {$uri} via array"), null],
    'direct_TextResourceContents' => ['returnTextResourceContents', 'text/special-contents', fn($text) => expect($text)->toBe('Direct TextResourceContents'), null],
    'direct_BlobResourceContents' => ['returnBlobResourceContents', 'application/custom-blob-contents', null, fn($blob) => expect(base64_decode($blob ?? ''))->toBe('blobbycontents')],
    'direct_EmbeddedResource' => ['returnEmbeddedResource', 'application/vnd.custom-embedded', fn($text) => expect($text)->toBe('Direct EmbeddedResource content'), null],
]);

it('formats various handler return types correctly', function (string $handlerMethod, string $expectedMime, ?callable $textAssertion, ?callable $blobAssertion) {
    $schema = ResourceSchema::make($this->testUri, 'format-test');
    $resource = RegisteredResource::make($schema, [ResourceHandlerFixture::class, $handlerMethod]);

    $resultContents = $resource->read($this->container, $this->testUri);

    expect($resultContents)->toBeArray()->toHaveCount(1);
    $content = $resultContents[0];

    expect($content->uri)->toBe($this->testUri);
    expect($content->mimeType)->toBe($expectedMime);

    if ($textAssertion) {
        expect($content)->toBeInstanceOf(TextResourceContents::class);
        $textAssertion($content->text, $this->testUri);
    }
    if ($blobAssertion) {
        expect($content)->toBeInstanceOf(BlobResourceContents::class);
        $blobAssertion($content->blob, $this->testUri);
    }
})->with('resource_handler_return_types');

it('formats SplFileInfo based on schema MIME type (text)', function () {
    $schema = ResourceSchema::make($this->testUri, 'spl-text', mimeType: 'text/markdown');
    $resource = RegisteredResource::make($schema, [ResourceHandlerFixture::class, 'returnSplFileInfo']);
    $result = $resource->read($this->container, $this->testUri);

    expect($result[0])->toBeInstanceOf(TextResourceContents::class);
    expect($result[0]->mimeType)->toBe('text/markdown');
    expect($result[0]->text)->toBe("Content from SplFileInfo for {$this->testUri}");
});

it('formats SplFileInfo based on schema MIME type (blob if not text like)', function () {
    $schema = ResourceSchema::make($this->testUri, 'spl-blob', mimeType: 'image/png');
    $resource = RegisteredResource::make($schema, [ResourceHandlerFixture::class, 'returnSplFileInfo']);
    $result = $resource->read($this->container, $this->testUri);

    expect($result[0])->toBeInstanceOf(BlobResourceContents::class);
    expect($result[0]->mimeType)->toBe('image/png');
    expect(base64_decode($result[0]->blob ?? ''))->toBe("Content from SplFileInfo for {$this->testUri}");
});

it('formats array of ResourceContents as is', function () {
    $resource = RegisteredResource::make($this->resourceSchema, [ResourceHandlerFixture::class, 'returnArrayOfResourceContents']);
    $results = $resource->read($this->container, $this->testUri);
    expect($results)->toHaveCount(2);
    expect($results[0])->toBeInstanceOf(TextResourceContents::class)->text->toBe('Part 1 of many RC');
    expect($results[1])->toBeInstanceOf(BlobResourceContents::class)->blob->toBe(base64_encode('pngdata'));
});

it('formats array of EmbeddedResources by extracting their inner resource', function () {
    $resource = RegisteredResource::make($this->resourceSchema, [ResourceHandlerFixture::class, 'returnArrayOfEmbeddedResources']);
    $results = $resource->read($this->container, $this->testUri);
    expect($results)->toHaveCount(2);
    expect($results[0])->toBeInstanceOf(TextResourceContents::class)->text->toBe('<doc1/>');
    expect($results[1])->toBeInstanceOf(BlobResourceContents::class)->blob->toBe(base64_encode('fontdata'));
});

it('formats mixed array with ResourceContent/EmbeddedResource by processing each item', function () {
    $resource = RegisteredResource::make($this->resourceSchema, [ResourceHandlerFixture::class, 'returnMixedArrayWithResourceTypes']);
    $results = $resource->read($this->container, $this->testUri);

    expect($results)->toBeArray()->toHaveCount(4);
    expect($results[0])->toBeInstanceOf(TextResourceContents::class)->text->toBe("A raw string piece");
    expect($results[1])->toBeInstanceOf(TextResourceContents::class)->text->toBe("**Markdown!**");
    expect($results[2])->toBeInstanceOf(TextResourceContents::class);
    expect(json_decode($results[2]->text, true))->toEqual(['nested_array_data' => 'value', 'for_uri' => $this->testUri]);
    expect($results[3])->toBeInstanceOf(TextResourceContents::class)->text->toBe("col1,col2");
});


it('propagates McpServerException from handler during read', function () {
    $resource = RegisteredResource::make(
        $this->resourceSchema,
        [ResourceHandlerFixture::class, 'resourceHandlerNeedsUri']
    );
    $this->container->shouldReceive('get')->with(ResourceHandlerFixture::class)->andReturn(
        Mockery::mock(ResourceHandlerFixture::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('resourceHandlerNeedsUri')->andThrow(McpServerException::invalidParams("Test error"));
        })
    );
    $resource->read($this->container, $this->testUri);
})->throws(McpServerException::class, "Test error");

it('propagates other exceptions from handler during read', function () {
    $resource = RegisteredResource::make($this->resourceSchema, [ResourceHandlerFixture::class, 'handlerThrowsException']);
    $resource->read($this->container, $this->testUri);
})->throws(\DomainException::class, "Cannot read resource");

it('throws RuntimeException for unformattable handler result', function () {
    $resource = RegisteredResource::make($this->resourceSchema, [ResourceHandlerFixture::class, 'returnUnformattableType']);
    $resource->read($this->container, $this->testUri);
})->throws(\RuntimeException::class, "Cannot format resource read result for URI");


it('can be serialized to array and deserialized', function () {
    $original = RegisteredResource::make(
        ResourceSchema::make(
            'uri://test',
            'my-resource',
            'desc',
            'app/foo',
        ),
        [ResourceHandlerFixture::class, 'getStaticText'],
        true
    );

    $array = $original->toArray();

    expect($array['schema']['uri'])->toBe('uri://test');
    expect($array['schema']['name'])->toBe('my-resource');
    expect($array['schema']['description'])->toBe('desc');
    expect($array['schema']['mimeType'])->toBe('app/foo');
    expect($array['handler'])->toBe([ResourceHandlerFixture::class, 'getStaticText']);
    expect($array['isManual'])->toBeTrue();

    $rehydrated = RegisteredResource::fromArray($array);
    expect($rehydrated)->toBeInstanceOf(RegisteredResource::class);
    expect($rehydrated->schema->uri)->toEqual($original->schema->uri);
    expect($rehydrated->schema->name)->toEqual($original->schema->name);
    expect($rehydrated->isManual)->toBeTrue();
});

it('fromArray returns false on failure', function () {
    $badData = ['schema' => ['uri' => 'fail']];
    expect(RegisteredResource::fromArray($badData))->toBeFalse();
});
