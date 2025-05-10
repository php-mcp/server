<?php

namespace PhpMcp\Server\Tests\Unit\Traits;

use JsonException;
use Mockery;
use PhpMcp\Server\JsonRpc\Contents\AudioContent;
use PhpMcp\Server\JsonRpc\Contents\EmbeddedResource;
use PhpMcp\Server\JsonRpc\Contents\ImageContent;
use PhpMcp\Server\JsonRpc\Contents\PromptMessage;
use PhpMcp\Server\JsonRpc\Contents\ResourceContent;
use PhpMcp\Server\JsonRpc\Contents\TextContent;
use PhpMcp\Server\Traits\ResponseFormatter;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use stdClass;

// --- Test Class Using the Trait ---
class TestFormatterClass
{
    use ResponseFormatter {
        formatToolResult as public;
        formatToolErrorResult as public;
        formatResourceContents as public;
        formatPromptMessages as public;
    }

    public LoggerInterface $logger;
}

beforeEach(function () {
    $this->formatter = new TestFormatterClass();
    /** @var \Mockery\MockInterface&\Psr\Log\LoggerInterface */
    $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    $this->formatter->logger = $this->loggerMock;

    // For SplFileInfo test
    $this->tempFilePath = tempnam(sys_get_temp_dir(), 'resfmt_');
    file_put_contents($this->tempFilePath, 'splfile test content');
});

afterEach(function () {
    // Clean up temp file
    if (isset($this->tempFilePath) && file_exists($this->tempFilePath)) {
        unlink($this->tempFilePath);
    }
});

// --- formatToolResult Tests ---

test('formatToolResult handles scalars', function ($input, $expectedText) {
    $result = $this->formatter->formatToolResult($input);

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(TextContent::class)
        ->and($result[0]->getText())->toBe($expectedText);
})->with([
    ['hello world', 'hello world'],
    [12345, '12345'],
    [98.76, '98.76'],
    [true, 'true'],
    [false, 'false'],
]);

test('formatToolResult handles null', function () {
    $result = $this->formatter->formatToolResult(null);

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(TextContent::class)
        ->and($result[0]->getText())->toBe('(null)');
});

test('formatToolResult handles array (JSON encodes)', function () {
    $data = ['key' => 'value', 'list' => [1, null, true]];
    $expectedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = $this->formatter->formatToolResult($data);

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(TextContent::class)
        ->and($result[0]->getText())->toBe($expectedJson);
});

test('formatToolResult handles object (JSON encodes)', function () {
    $data = new stdClass();
    $data->key = 'value';
    $data->list = [1, null, true];
    $expectedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = $this->formatter->formatToolResult($data);
    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(TextContent::class)
        ->and($result[0]->getText())->toBe($expectedJson);
});

test('formatToolResult handles single Content object', function () {
    $content = new ImageContent('base64data', 'image/png');
    $result = $this->formatter->formatToolResult($content);

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBe($content);
});

test('formatToolResult handles array of Content objects', function () {
    $contentArray = [new TextContent('one'), new TextContent('two')];
    $result = $this->formatter->formatToolResult($contentArray);

    expect($result)->toBe($contentArray);
});

test('formatToolResult throws JsonException for unencodable value', function () {
    $resource = fopen('php://memory', 'r');
    $this->formatter->formatToolResult($resource);
    if (is_resource($resource)) {
        fclose($resource);
    }
})->throws(JsonException::class);

// --- formatToolErrorResult Tests ---

test('formatToolErrorResult creates correct TextContent', function () {
    $exception = new \RuntimeException('Something went wrong');
    $result = $this->formatter->formatToolErrorResult($exception);

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(TextContent::class)
        ->and($result[0]->getText())->toBe('Tool execution failed: Something went wrong (Type: RuntimeException)');
});

// --- formatResourceContents Tests ---

test('formatResourceContents handles EmbeddedResource input', function () {
    $resource = new EmbeddedResource('test/uri', 'text/plain', 'content');
    $result = $this->formatter->formatResourceContents($resource, 'test/uri', 'text/plain');

    expect($result)->toBe([$resource]);
});

test('formatResourceContents handles ResourceContent input', function () {
    $embedded = new EmbeddedResource('test/uri', 'text/plain', 'content');
    $resourceContent = new ResourceContent($embedded);
    $result = $this->formatter->formatResourceContents($resourceContent, 'test/uri', 'text/plain');

    expect($result)->toEqual([$embedded]);
});

test('formatResourceContents handles array of EmbeddedResource input', function () {
    $resources = [
        new EmbeddedResource('test/uri1', 'text/plain', 'content1'),
        new EmbeddedResource('test/uri2', 'image/png', null, 'blob2'),
    ];
    $result = $this->formatter->formatResourceContents($resources, 'test/uri', 'text/plain');

    expect($result)->toBe($resources);
});

test('formatResourceContents handles array of ResourceContent input', function () {
    $embedded1 = new EmbeddedResource('test/uri1', 'text/plain', 'content1');
    $embedded2 = new EmbeddedResource('test/uri2', 'image/png', null, 'blob2');
    $resourceContents = [new ResourceContent($embedded1), new ResourceContent($embedded2)];
    $result = $this->formatter->formatResourceContents($resourceContents, 'test/uri', 'text/plain');

    expect($result)->toEqual([$embedded1, $embedded2]);
});

test('formatResourceContents handles string input (guessing text mime)', function () {
    $result = $this->formatter->formatResourceContents('Simple text', 'test/uri', null);

    expect($result)->toEqual([new EmbeddedResource('test/uri', 'text/plain', 'Simple text')]);
});

test('formatResourceContents handles string input (guessing json mime)', function () {
    $result = $this->formatter->formatResourceContents('{"key":"value"}', 'test/uri', null);

    expect($result)->toEqual([new EmbeddedResource('test/uri', 'application/json', '{"key":"value"}')]);
});

test('formatResourceContents handles string input (guessing html mime)', function () {
    $result = $this->formatter->formatResourceContents('<html><body>Hi</body></html>', 'test/uri', null);

    expect($result)->toEqual([new EmbeddedResource('test/uri', 'text/html', '<html><body>Hi</body></html>')]);
});

test('formatResourceContents handles string input (with default mime)', function () {
    $result = $this->formatter->formatResourceContents('Specific content', 'test/uri', 'text/csv');

    expect($result)->toEqual([new EmbeddedResource('test/uri', 'text/csv', 'Specific content')]);
});

test('formatResourceContents handles stream input', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'stream content');
    rewind($stream);

    $result = $this->formatter->formatResourceContents($stream, 'test/uri', 'application/pdf');

    // Stream should be closed after reading
    // expect(is_resource($stream))->toBeFalse();
    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(EmbeddedResource::class)
        ->and($result[0]->getUri())->toBe('test/uri')
        ->and($result[0]->getMimeType())->toBe('application/pdf')
        ->and($result[0]->getText())->toBeNull()
        ->and($result[0]->getBlob())->toBe(base64_encode('stream content'));
});

test('formatResourceContents handles array blob input', function () {
    $data = ['blob' => base64_encode('binary'), 'mimeType' => 'image/jpeg'];
    $result = $this->formatter->formatResourceContents($data, 'test/uri', 'application/octet-stream');

    expect($result)->toEqual([new EmbeddedResource('test/uri', 'image/jpeg', null, $data['blob'])]);
});

test('formatResourceContents handles array text input', function () {
    $data = ['text' => 'hello', 'mimeType' => 'text/markdown'];
    $result = $this->formatter->formatResourceContents($data, 'test/uri', 'text/plain');

    expect($result)->toEqual([new EmbeddedResource('test/uri', 'text/markdown', 'hello')]);
});

test('formatResourceContents handles SplFileInfo input', function () {
    $splFile = new SplFileInfo($this->tempFilePath);
    $result = $this->formatter->formatResourceContents($splFile, 'test/uri', 'text/vnd.test');
    $result2 = $this->formatter->formatResourceContents($splFile, 'test/uri', 'image/png');

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(EmbeddedResource::class)
        ->and($result[0]->getUri())->toBe('test/uri')
        ->and($result[0]->getMimeType())->toBe('text/vnd.test')
        ->and($result[0]->getText())->toBe('splfile test content')
        ->and($result[0]->getBlob())->toBeNull();

    expect($result2)->toBeArray()->toHaveCount(1)
        ->and($result2[0])->toBeInstanceOf(EmbeddedResource::class)
        ->and($result2[0]->getUri())->toBe('test/uri')
        ->and($result2[0]->getMimeType())->toBe('image/png')
        ->and($result2[0]->getText())->toBeNull()
        ->and($result2[0]->getBlob())->toBe(base64_encode('splfile test content'));
});

test('formatResourceContents handles array input (json mime)', function () {
    $data = ['a' => 1];
    $expectedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $result = $this->formatter->formatResourceContents($data, 'test/uri', 'application/json');
    expect($result)->toEqual([new EmbeddedResource('test/uri', 'application/json', $expectedJson)]);
});

test('formatResourceContents handles array input (non-json mime, logs warning)', function () {
    $data = ['b' => 2];
    $expectedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $this->loggerMock->shouldReceive('warning')->once()->with('MCP SDK: Automatically converted array to JSON for resource', Mockery::any());
    $result = $this->formatter->formatResourceContents($data, 'test/uri', 'text/plain');
    // It should convert to JSON and use application/json mime type
    expect($result)->toEqual([new EmbeddedResource('test/uri', 'application/json', $expectedJson)]);
});

test('formatResourceContents throws exception for unformattable type', function () {
    $data = new stdClass(); // Simple object not handled directly
    $this->formatter->formatResourceContents($data, 'test/uri', 'text/plain');
})->throws(\RuntimeException::class, 'Cannot format resource read result');

// --- formatPromptMessages Tests ---

test('formatPromptMessages handles array of PromptMessage input', function () {
    $messages = [PromptMessage::user('Hi'), PromptMessage::assistant('Hello')];
    $result = $this->formatter->formatPromptMessages($messages);
    expect($result)->toBe($messages);
});

test('formatPromptMessages handles simple role=>text array', function () {
    $input = ['user' => 'User input', 'assistant' => 'Assistant reply'];
    $expected = [PromptMessage::user('User input'), PromptMessage::assistant('Assistant reply')];
    $result = $this->formatter->formatPromptMessages($input);
    expect($result)->toEqual($expected);
});

test('formatPromptMessages handles list of [role, content] arrays', function () {
    $input = [
        ['role' => 'user', 'content' => 'First turn'],
        ['role' => 'assistant', 'content' => 'Okay'],
        ['role' => 'user', 'content' => new TextContent('Use content obj')],
        ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'Use text obj']],
        ['role' => 'user', 'content' => ['type' => 'image', 'mimeType' => 'image/png', 'data' => 'abc']],
        ['role' => 'assistant', 'content' => ['type' => 'audio', 'mimeType' => 'audio/mpeg', 'data' => 'def']],
        ['role' => 'user', 'content' => ['type' => 'resource', 'resource' => ['uri' => 'res/1', 'text' => 'res text']]],
        ['role' => 'assistant', 'content' => ['type' => 'resource', 'resource' => ['uri' => 'res/2', 'blob' => 'ghi', 'mimeType' => 'app/bin']]],
    ];
    $expected = [
        PromptMessage::user('First turn'),
        PromptMessage::assistant('Okay'),
        new PromptMessage('user', new TextContent('Use content obj')),
        new PromptMessage('assistant', new TextContent('Use text obj')),
        new PromptMessage('user', new ImageContent('abc', 'image/png')),
        new PromptMessage('assistant', new AudioContent('def', 'audio/mpeg')),
        new PromptMessage('user', new ResourceContent(new EmbeddedResource('res/1', 'text/plain', 'res text'))),
        new PromptMessage('assistant', new ResourceContent(new EmbeddedResource('res/2', 'app/bin', null, 'ghi'))),
    ];
    $result = $this->formatter->formatPromptMessages($input);
    expect($result)->toEqual($expected);
});

test('formatPromptMessages throws for non-array input', function () {
    $this->formatter->formatPromptMessages('not an array');
})->throws(\RuntimeException::class, 'must return an array of messages');

test('formatPromptMessages throws for non-list array input', function () {
    $this->formatter->formatPromptMessages(['a' => 'b']); // Assoc array
})->throws(\RuntimeException::class, 'must return a list (sequential array)');

test('formatPromptMessages throws for invalid message structure (missing role)', function () {
    $this->formatter->formatPromptMessages([['content' => 'text']]);
})->throws(\RuntimeException::class, 'Expected a PromptMessage or an array with \'role\' and \'content\'');

test('formatPromptMessages throws for invalid role', function () {
    $this->formatter->formatPromptMessages([['role' => 'system', 'content' => 'text']]);
})->throws(\RuntimeException::class, 'Invalid role \'system\'');

test('formatPromptMessages throws for invalid content type', function () {
    $this->formatter->formatPromptMessages([['role' => 'user', 'content' => ['type' => 'video', 'url' => '...']]]);
})->throws(\RuntimeException::class, "Invalid content type 'video'");

test('formatPromptMessages throws for invalid resource content (missing uri)', function () {
    $this->formatter->formatPromptMessages([['role' => 'user', 'content' => ['type' => 'resource', 'resource' => ['text' => '...']]]]);
})->throws(\RuntimeException::class, "Missing or invalid 'uri'");

test('formatPromptMessages throws for invalid resource content (missing text/blob)', function () {
    $this->formatter->formatPromptMessages([['role' => 'user', 'content' => ['type' => 'resource', 'resource' => ['uri' => 'res/1']]]]);
})->throws(\RuntimeException::class, "Must contain 'text' or 'blob'");
