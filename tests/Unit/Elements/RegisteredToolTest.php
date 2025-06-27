<?php

namespace PhpMcp\Server\Tests\Unit\Elements;

use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpMcp\Schema\Tool;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\ImageContent;
use PhpMcp\Server\Tests\Fixtures\General\ToolHandlerFixture;
use Psr\Container\ContainerInterface;
use JsonException;
use PhpMcp\Server\Exception\McpServerException;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->handlerInstance = new ToolHandlerFixture();
    $this->container->shouldReceive('get')->with(ToolHandlerFixture::class)
        ->andReturn($this->handlerInstance)->byDefault();

    $this->toolSchema = Tool::make(
        name: 'test-tool',
        inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]
    );

    $this->registeredTool = RegisteredTool::make(
        $this->toolSchema,
        [ToolHandlerFixture::class, 'greet']
    );
});

it('constructs correctly and exposes schema', function () {
    expect($this->registeredTool->schema)->toBe($this->toolSchema);
    expect($this->registeredTool->handler)->toBe([ToolHandlerFixture::class, 'greet']);
    expect($this->registeredTool->isManual)->toBeFalse();
});

it('can be made as a manual registration', function () {
    $manualTool = RegisteredTool::make($this->toolSchema, [ToolHandlerFixture::class, 'greet'], true);
    expect($manualTool->isManual)->toBeTrue();
});

it('calls the handler with prepared arguments', function () {
    $tool = RegisteredTool::make(
        Tool::make('sum-tool', ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]]),
        [ToolHandlerFixture::class, 'sum']
    );
    $mockHandler = Mockery::mock(ToolHandlerFixture::class);
    $mockHandler->shouldReceive('sum')->with(5, 10)->once()->andReturn(15);
    $this->container->shouldReceive('get')->with(ToolHandlerFixture::class)->andReturn($mockHandler);

    $resultContents = $tool->call($this->container, ['a' => 5, 'b' => '10']); // '10' will be cast to int by prepareArguments

    expect($resultContents)->toBeArray()->toHaveCount(1);
    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe('15');
});

it('calls handler with no arguments if tool takes none and none provided', function () {
    $tool = RegisteredTool::make(
        Tool::make('no-args-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'noParamsTool']
    );
    $mockHandler = Mockery::mock(ToolHandlerFixture::class);
    $mockHandler->shouldReceive('noParamsTool')->withNoArgs()->once()->andReturn(['status' => 'done']);
    $this->container->shouldReceive('get')->with(ToolHandlerFixture::class)->andReturn($mockHandler);

    $resultContents = $tool->call($this->container, []);
    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe(json_encode(['status' => 'done'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});


dataset('tool_handler_return_values', [
    'string'        => ['returnString', "This is a string result."],
    'integer'       => ['returnInteger', "12345"],
    'float'         => ['returnFloat', "67.89"],
    'boolean_true'  => ['returnBooleanTrue', "true"],
    'boolean_false' => ['returnBooleanFalse', "false"],
    'null'          => ['returnNull', "(null)"],
    'array_to_json' => ['returnArray', json_encode(['message' => 'Array result', 'data' => [1, 2, 3]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    'object_to_json' => ['returnStdClass', json_encode((object)['property' => "value"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
]);

it('formats various scalar and simple object/array handler results into TextContent', function (string $handlerMethod, string $expectedText) {
    $tool = RegisteredTool::make(
        Tool::make('format-test-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, $handlerMethod]
    );

    $resultContents = $tool->call($this->container, []);

    expect($resultContents)->toBeArray()->toHaveCount(1);
    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe($expectedText);
})->with('tool_handler_return_values');

it('returns single Content object from handler as array with one Content object', function () {
    $tool = RegisteredTool::make(
        Tool::make('content-test-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'returnTextContent']
    );
    $resultContents = $tool->call($this->container, []);

    expect($resultContents)->toBeArray()->toHaveCount(1);
    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe("Pre-formatted TextContent.");
});

it('returns array of Content objects from handler as is', function () {
    $tool = RegisteredTool::make(
        Tool::make('content-array-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'returnArrayOfContent']
    );
    $resultContents = $tool->call($this->container, []);

    expect($resultContents)->toBeArray()->toHaveCount(2);
    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe("Part 1");
    expect($resultContents[1])->toBeInstanceOf(ImageContent::class)->data->toBe("imgdata");
});

it('formats mixed array from handler into array of Content objects', function () {
    $tool = RegisteredTool::make(
        Tool::make('mixed-array-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'returnMixedArray']
    );
    $resultContents = $tool->call($this->container, []);

    expect($resultContents)->toBeArray()->toHaveCount(8);

    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe("A raw string");
    expect($resultContents[1])->toBeInstanceOf(TextContent::class)->text->toBe("A TextContent object"); // Original TextContent is preserved
    expect($resultContents[2])->toBeInstanceOf(TextContent::class)->text->toBe("123");
    expect($resultContents[3])->toBeInstanceOf(TextContent::class)->text->toBe("true");
    expect($resultContents[4])->toBeInstanceOf(TextContent::class)->text->toBe("(null)");
    expect($resultContents[5])->toBeInstanceOf(TextContent::class)->text->toBe(json_encode(['nested_key' => 'nested_value', 'sub_array' => [4, 5]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    expect($resultContents[6])->toBeInstanceOf(ImageContent::class)->data->toBe("img_data_mixed"); // Original ImageContent is preserved
    expect($resultContents[7])->toBeInstanceOf(TextContent::class)->text->toBe(json_encode((object)['obj_prop' => 'obj_val'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

it('formats empty array from handler into TextContent with "[]"', function () {
    $tool = RegisteredTool::make(
        Tool::make('empty-array-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'returnEmptyArray']
    );
    $resultContents = $tool->call($this->container, []);

    expect($resultContents)->toBeArray()->toHaveCount(1);
    expect($resultContents[0])->toBeInstanceOf(TextContent::class)->text->toBe('[]');
});

it('throws JsonException during formatResult if handler returns unencodable value', function () {
    $tool = RegisteredTool::make(
        Tool::make('unencodable-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'toolUnencodableResult']
    );
    $tool->call($this->container, []);
})->throws(JsonException::class);

it('re-throws exceptions from handler execution wrapped in McpServerException from handle()', function () {
    $tool = RegisteredTool::make(
        Tool::make('exception-tool', ['type' => 'object', 'properties' => []]),
        [ToolHandlerFixture::class, 'toolThatThrows']
    );

    $this->container->shouldReceive('get')->with(ToolHandlerFixture::class)->once()->andReturn(new ToolHandlerFixture());

    $tool->call($this->container, []);
})->throws(InvalidArgumentException::class, "Something went wrong in the tool.");
