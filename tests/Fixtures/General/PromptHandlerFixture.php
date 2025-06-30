<?php

namespace PhpMcp\Server\Tests\Fixtures\General;

use PhpMcp\Schema\Content\PromptMessage;
use PhpMcp\Schema\Enum\Role;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\ImageContent;
use PhpMcp\Schema\Content\AudioContent;
use PhpMcp\Server\Attributes\CompletionProvider;
use Psr\Log\LoggerInterface;

class PromptHandlerFixture
{
    public function generateSimpleGreeting(string $name, string $style = "friendly"): array
    {
        return [
            ['role' => 'user', 'content' => "Craft a {$style} greeting for {$name}."]
        ];
    }

    public function returnSinglePromptMessageObject(): PromptMessage
    {
        return PromptMessage::make(Role::User, TextContent::make("Single PromptMessage object."));
    }

    public function returnArrayOfPromptMessageObjects(): array
    {
        return [
            PromptMessage::make(Role::User, TextContent::make("First message object.")),
            PromptMessage::make(Role::Assistant, ImageContent::make("img_data", "image/png")),
        ];
    }

    public function returnEmptyArrayForPrompt(): array
    {
        return [];
    }

    public function returnSimpleUserAssistantMap(): array
    {
        return [
            'user' => "This is the user's turn.",
            'assistant' => "And this is the assistant's reply."
        ];
    }

    public function returnUserAssistantMapWithContentObjects(): array
    {
        return [
            'user' => TextContent::make("User text content object."),
            'assistant' => ImageContent::make("asst_img_data", "image/gif"),
        ];
    }

    public function returnUserAssistantMapWithMixedContent(): array
    {
        return [
            'user' => "Plain user string.",
            'assistant' => AudioContent::make("aud_data", "audio/mp3"),
        ];
    }

    public function returnUserAssistantMapWithArrayContent(): array
    {
        return [
            'user' => ['type' => 'text', 'text' => 'User array content'],
            'assistant' => ['type' => 'image', 'data' => 'asst_arr_img_data', 'mimeType' => 'image/jpeg'],
        ];
    }

    public function returnListOfRawMessageArrays(): array
    {
        return [
            ['role' => 'user', 'content' => "First raw message string."],
            ['role' => 'assistant', 'content' => TextContent::make("Second raw message with Content obj.")],
            ['role' => 'user', 'content' => ['type' => 'image', 'data' => 'raw_img_data', 'mimeType' => 'image/webp']],
            ['role' => 'assistant', 'content' => ['type' => 'audio', 'data' => 'raw_aud_data', 'mimeType' => 'audio/ogg']],
            ['role' => 'user', 'content' => ['type' => 'resource', 'resource' => ['uri' => 'file://doc.pdf', 'blob' => base64_encode('pdf-data'), 'mimeType' => 'application/pdf']]],
            ['role' => 'assistant', 'content' => ['type' => 'resource', 'resource' => ['uri' => 'config://settings.json', 'text' => '{"theme":"dark"}']]],
        ];
    }

    public function returnListOfRawMessageArraysWithScalars(): array
    {
        return [
            ['role' => 'user', 'content' => 123],          // int
            ['role' => 'assistant', 'content' => true],       // bool
            ['role' => 'user', 'content' => null],         // null
            ['role' => 'assistant', 'content' => 3.14],       // float
            ['role' => 'user', 'content' => ['key' => 'value']], // array that becomes JSON
        ];
    }

    public function returnMixedArrayOfPromptMessagesAndRaw(): array
    {
        return [
            PromptMessage::make(Role::User, TextContent::make("This is a PromptMessage object.")),
            ['role' => 'assistant', 'content' => "This is a raw message array."],
            PromptMessage::make(Role::User, ImageContent::make("pm_img", "image/bmp")),
            ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'Raw message with typed content.']],
        ];
    }

    public function promptWithArgumentCompletion(
        #[CompletionProvider(provider: CompletionProviderFixture::class)]
        string $entityName,
        string $action = "describe"
    ): array {
        return [
            ['role' => 'user', 'content' => "Please {$action} the entity: {$entityName}."]
        ];
    }

    public function promptReturnsNonArray(): string
    {
        return "This is not a valid prompt return type.";
    }

    public function promptReturnsArrayWithInvalidRole(): array
    {
        return [['role' => 'system', 'content' => 'System messages are not directly supported.']];
    }

    public function promptReturnsInvalidRole(): array
    {
        return [['role' => 'system', 'content' => 'System messages are not directly supported.']];
    }

    public function promptReturnsArrayWithInvalidContentStructure(): array
    {
        return [['role' => 'user', 'content' => ['text_only_no_type' => 'invalid']]];
    }

    public function promptReturnsArrayWithInvalidTypedContent(): array
    {
        return [['role' => 'user', 'content' => ['type' => 'image', 'source' => 'url.jpg']]]; // 'image' needs 'data' and 'mimeType'
    }

    public function promptReturnsArrayWithInvalidResourceContent(): array
    {
        return [
            [
                'role' => 'user',
                'content' => ['type' => 'resource', 'resource' => ['uri' => 'uri://uri']]
            ]
        ];
    }

    public function promptHandlerThrows(): void
    {
        throw new \LogicException("Prompt generation failed inside handler.");
    }
}
