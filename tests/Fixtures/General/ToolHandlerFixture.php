<?php

namespace PhpMcp\Server\Tests\Fixtures\General;

use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\ImageContent;
use PhpMcp\Schema\Content\AudioContent;
use PhpMcp\Server\Tests\Fixtures\Enums\BackedStringEnum;
use Psr\Log\LoggerInterface;

class ToolHandlerFixture
{
    public function __construct()
    {
    }

    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }

    public function sum(int $a, int $b): int
    {
        return $a + $b;
    }

    public function optionalParamsTool(string $required, ?string $optional = "default_val"): string
    {
        return "{$required} and {$optional}";
    }

    public function noParamsTool(): array
    {
        return ['status' => 'ok', 'timestamp' => time()];
    }

    public function processBackedEnum(BackedStringEnum $status): string
    {
        return "Status processed: " . $status->value;
    }

    public function returnString(): string
    {
        return "This is a string result.";
    }

    public function returnInteger(): int
    {
        return 12345;
    }

    public function returnFloat(): float
    {
        return 67.89;
    }

    public function returnBooleanTrue(): bool
    {
        return true;
    }

    public function returnBooleanFalse(): bool
    {
        return false;
    }

    public function returnNull(): ?string
    {
        return null;
    }

    public function returnArray(): array
    {
        return ['message' => 'Array result', 'data' => [1, 2, 3]];
    }

    public function returnStdClass(): \stdClass
    {
        $obj = new \stdClass();
        $obj->property = "value";
        return $obj;
    }

    public function returnTextContent(): TextContent
    {
        return TextContent::make("Pre-formatted TextContent.");
    }

    public function returnImageContent(): ImageContent
    {
        return ImageContent::make("base64data==", "image/png");
    }

    public function returnAudioContent(): AudioContent
    {
        return AudioContent::make("base64audio==", "audio/mp3");
    }

    public function returnArrayOfContent(): array
    {
        return [
            TextContent::make("Part 1"),
            ImageContent::make("imgdata", "image/jpeg")
        ];
    }

    public function returnMixedArray(): array
    {
        return [
            "A raw string",
            TextContent::make("A TextContent object"),
            123,
            true,
            null,
            ['nested_key' => 'nested_value', 'sub_array' => [4, 5]],
            ImageContent::make("img_data_mixed", "image/gif"),
            (object)['obj_prop' => 'obj_val']
        ];
    }

    public function returnEmptyArray(): array
    {
        return [];
    }

    public function toolThatThrows(): void
    {
        throw new \InvalidArgumentException("Something went wrong in the tool.");
    }

    public function toolUnencodableResult()
    {
        return fopen('php://memory', 'r');
    }
}
