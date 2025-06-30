<?php

namespace PhpMcp\Server\Tests\Unit\Elements;

use Mockery;
use PhpMcp\Schema\Prompt as PromptSchema;
use PhpMcp\Schema\PromptArgument;
use PhpMcp\Server\Elements\RegisteredPrompt;
use PhpMcp\Schema\Content\PromptMessage;
use PhpMcp\Schema\Enum\Role;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\ImageContent;
use PhpMcp\Schema\Content\AudioContent;
use PhpMcp\Schema\Content\EmbeddedResource;
use PhpMcp\Server\Tests\Fixtures\Enums\StatusEnum;
use PhpMcp\Server\Tests\Fixtures\General\PromptHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\CompletionProviderFixture;
use PhpMcp\Server\Tests\Unit\Attributes\TestEnum;
use Psr\Container\ContainerInterface;

beforeEach(function () {
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->container->shouldReceive('get')
        ->with(PromptHandlerFixture::class)
        ->andReturn(new PromptHandlerFixture())
        ->byDefault();

    $this->promptSchema = PromptSchema::make(
        'test-greeting-prompt',
        'Generates a greeting.',
        [PromptArgument::make('name', 'The name to greet.', true)]
    );
});

it('constructs correctly with schema, handler, and completion providers', function () {
    $providers = ['name' => CompletionProviderFixture::class];
    $prompt = RegisteredPrompt::make(
        $this->promptSchema,
        [PromptHandlerFixture::class, 'promptWithArgumentCompletion'],
        false,
        $providers
    );

    expect($prompt->schema)->toBe($this->promptSchema);
    expect($prompt->handler)->toBe([PromptHandlerFixture::class, 'promptWithArgumentCompletion']);
    expect($prompt->isManual)->toBeFalse();
    expect($prompt->completionProviders)->toEqual($providers);
    expect($prompt->completionProviders['name'])->toBe(CompletionProviderFixture::class);
    expect($prompt->completionProviders)->not->toHaveKey('nonExistentArg');
});

it('can be made as a manual registration', function () {
    $manualPrompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'generateSimpleGreeting'], true);
    expect($manualPrompt->isManual)->toBeTrue();
});

it('calls handler with prepared arguments via get()', function () {
    $handlerMock = Mockery::mock(PromptHandlerFixture::class);
    $handlerMock->shouldReceive('generateSimpleGreeting')
        ->with('Alice', 'warm')
        ->once()
        ->andReturn([['role' => 'user', 'content' => 'Warm greeting for Alice.']]);
    $this->container->shouldReceive('get')->with(PromptHandlerFixture::class)->andReturn($handlerMock);

    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'generateSimpleGreeting']);
    $messages = $prompt->get($this->container, ['name' => 'Alice', 'style' => 'warm']);

    expect($messages[0]->content->text)->toBe('Warm greeting for Alice.');
});

it('formats single PromptMessage object from handler', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnSinglePromptMessageObject']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toBeArray()->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(PromptMessage::class);
    expect($messages[0]->content->text)->toBe("Single PromptMessage object.");
});

it('formats array of PromptMessage objects from handler as is', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnArrayOfPromptMessageObjects']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toBeArray()->toHaveCount(2);
    expect($messages[0]->content->text)->toBe("First message object.");
    expect($messages[1]->content)->toBeInstanceOf(ImageContent::class);
});

it('formats empty array from handler as empty array', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnEmptyArrayForPrompt']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toBeArray()->toBeEmpty();
});

it('formats simple user/assistant map from handler', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnSimpleUserAssistantMap']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toHaveCount(2);
    expect($messages[0]->role)->toBe(Role::User);
    expect($messages[0]->content->text)->toBe("This is the user's turn.");
    expect($messages[1]->role)->toBe(Role::Assistant);
    expect($messages[1]->content->text)->toBe("And this is the assistant's reply.");
});

it('formats user/assistant map with Content objects', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnUserAssistantMapWithContentObjects']);
    $messages = $prompt->get($this->container, []);
    expect($messages[0]->role)->toBe(Role::User);
    expect($messages[0]->content)->toBeInstanceOf(TextContent::class)->text->toBe("User text content object.");
    expect($messages[1]->role)->toBe(Role::Assistant);
    expect($messages[1]->content)->toBeInstanceOf(ImageContent::class)->data->toBe("asst_img_data");
});

it('formats user/assistant map with mixed content (string and Content object)', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnUserAssistantMapWithMixedContent']);
    $messages = $prompt->get($this->container, []);
    expect($messages[0]->role)->toBe(Role::User);
    expect($messages[0]->content)->toBeInstanceOf(TextContent::class)->text->toBe("Plain user string.");
    expect($messages[1]->role)->toBe(Role::Assistant);
    expect($messages[1]->content)->toBeInstanceOf(AudioContent::class)->data->toBe("aud_data");
});

it('formats user/assistant map with array content', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnUserAssistantMapWithArrayContent']);
    $messages = $prompt->get($this->container, []);
    expect($messages[0]->role)->toBe(Role::User);
    expect($messages[0]->content)->toBeInstanceOf(TextContent::class)->text->toBe("User array content");
    expect($messages[1]->role)->toBe(Role::Assistant);
    expect($messages[1]->content)->toBeInstanceOf(ImageContent::class)->data->toBe("asst_arr_img_data");
});

it('formats list of raw message arrays with various content types', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnListOfRawMessageArrays']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toHaveCount(6);
    expect($messages[0]->content->text)->toBe("First raw message string.");
    expect($messages[1]->content)->toBeInstanceOf(TextContent::class);
    expect($messages[2]->content)->toBeInstanceOf(ImageContent::class)->data->toBe("raw_img_data");
    expect($messages[3]->content)->toBeInstanceOf(AudioContent::class)->data->toBe("raw_aud_data");
    expect($messages[4]->content)->toBeInstanceOf(EmbeddedResource::class);
    expect($messages[4]->content->resource->blob)->toBe(base64_encode('pdf-data'));
    expect($messages[5]->content)->toBeInstanceOf(EmbeddedResource::class);
    expect($messages[5]->content->resource->text)->toBe('{"theme":"dark"}');
});

it('formats list of raw message arrays with scalar or array content (becoming JSON TextContent)', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnListOfRawMessageArraysWithScalars']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toHaveCount(5);
    expect($messages[0]->content->text)->toBe("123");
    expect($messages[1]->content->text)->toBe("true");
    expect($messages[2]->content->text)->toBe("(null)");
    expect($messages[3]->content->text)->toBe("3.14");
    expect($messages[4]->content->text)->toBe(json_encode(['key' => 'value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

it('formats mixed array of PromptMessage objects and raw message arrays', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'returnMixedArrayOfPromptMessagesAndRaw']);
    $messages = $prompt->get($this->container, []);
    expect($messages)->toHaveCount(4);
    expect($messages[0]->content)->toBeInstanceOf(TextContent::class)->text->toBe("This is a PromptMessage object.");
    expect($messages[1]->content)->toBeInstanceOf(TextContent::class)->text->toBe("This is a raw message array.");
    expect($messages[2]->content)->toBeInstanceOf(ImageContent::class)->data->toBe("pm_img");
    expect($messages[3]->content)->toBeInstanceOf(TextContent::class)->text->toBe("Raw message with typed content.");
});


dataset('prompt_format_errors', [
    'non_array_return' => ['promptReturnsNonArray', '/Prompt generator method must return an array/'],
    'invalid_role_in_array' => ['promptReturnsInvalidRole', "/Invalid role 'system'/"],
    'invalid_content_structure_in_array' => ['promptReturnsArrayWithInvalidContentStructure', "/Invalid message format at index 0. Expected an array with 'role' and 'content' keys./"], // More specific from formatMessage
    'invalid_typed_content_in_array' => ['promptReturnsArrayWithInvalidTypedContent', "/Invalid 'image' content at index 0: Missing or invalid 'data' string/"],
    'invalid_resource_content_in_array' => ['promptReturnsArrayWithInvalidResourceContent', "/Invalid resource at index 0: Must contain 'text' or 'blob'./"],
]);

it('throws RuntimeException for invalid prompt result formats', function (string|callable $handlerMethodOrCallable, string $expectedErrorPattern) {
    $methodName = is_string($handlerMethodOrCallable) ? $handlerMethodOrCallable : 'customReturn';
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, $methodName]);

    if (is_callable($handlerMethodOrCallable)) {
        $this->container->shouldReceive('get')->with(PromptHandlerFixture::class)->andReturn(
            Mockery::mock(PromptHandlerFixture::class, [$methodName => $handlerMethodOrCallable()])
        );
    }

    try {
        $prompt->get($this->container, []);
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toMatch($expectedErrorPattern);
    }

    expect($prompt->toArray())->toBeArray();
})->with('prompt_format_errors');


it('propagates exceptions from handler during get()', function () {
    $prompt = RegisteredPrompt::make($this->promptSchema, [PromptHandlerFixture::class, 'promptHandlerThrows']);
    $prompt->get($this->container, []);
})->throws(\LogicException::class, "Prompt generation failed inside handler.");


it('can be serialized to array and deserialized with completion providers', function () {
    $schema = PromptSchema::make(
        'serialize-prompt',
        'Test SerDe',
        [PromptArgument::make('arg1', required: true), PromptArgument::make('arg2', 'description for arg2')]
    );
    $providers = ['arg1' => CompletionProviderFixture::class];
    $serializedProviders = ['arg1' => serialize(CompletionProviderFixture::class)];
    $original = RegisteredPrompt::make(
        $schema,
        [PromptHandlerFixture::class, 'generateSimpleGreeting'],
        true,
        $providers
    );

    $array = $original->toArray();

    expect($array['schema']['name'])->toBe('serialize-prompt');
    expect($array['schema']['arguments'])->toHaveCount(2);
    expect($array['handler'])->toBe([PromptHandlerFixture::class, 'generateSimpleGreeting']);
    expect($array['isManual'])->toBeTrue();
    expect($array['completionProviders'])->toEqual($serializedProviders);

    $rehydrated = RegisteredPrompt::fromArray($array);
    expect($rehydrated)->toBeInstanceOf(RegisteredPrompt::class);
    expect($rehydrated->schema->name)->toEqual($original->schema->name);
    expect($rehydrated->isManual)->toBeTrue();
    expect($rehydrated->completionProviders)->toEqual($providers);
});

it('fromArray returns false on failure for prompt', function () {
    $badData = ['schema' => ['name' => 'fail']];
    expect(RegisteredPrompt::fromArray($badData))->toBeFalse();
});

it('can be serialized with ListCompletionProvider instances', function () {
    $schema = PromptSchema::make(
        'list-prompt',
        'Test list completion',
        [PromptArgument::make('status')]
    );
    $listProvider = new \PhpMcp\Server\Defaults\ListCompletionProvider(['draft', 'published', 'archived']);
    $providers = ['status' => $listProvider];

    $original = RegisteredPrompt::make(
        $schema,
        [PromptHandlerFixture::class, 'generateSimpleGreeting'],
        true,
        $providers
    );

    $array = $original->toArray();
    expect($array['completionProviders']['status'])->toBeString(); // Serialized instance

    $rehydrated = RegisteredPrompt::fromArray($array);
    expect($rehydrated->completionProviders['status'])->toBeInstanceOf(\PhpMcp\Server\Defaults\ListCompletionProvider::class);
});

it('can be serialized with EnumCompletionProvider instances', function () {
    $schema = PromptSchema::make(
        'enum-prompt',
        'Test enum completion',
        [PromptArgument::make('priority')]
    );

    $enumProvider = new \PhpMcp\Server\Defaults\EnumCompletionProvider(StatusEnum::class);
    $providers = ['priority' => $enumProvider];

    $original = RegisteredPrompt::make(
        $schema,
        [PromptHandlerFixture::class, 'generateSimpleGreeting'],
        true,
        $providers
    );

    $array = $original->toArray();
    expect($array['completionProviders']['priority'])->toBeString(); // Serialized instance

    $rehydrated = RegisteredPrompt::fromArray($array);
    expect($rehydrated->completionProviders['priority'])->toBeInstanceOf(\PhpMcp\Server\Defaults\EnumCompletionProvider::class);
});
