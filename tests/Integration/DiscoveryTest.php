<?php

use PhpMcp\Server\Elements\RegisteredPrompt;
use PhpMcp\Server\Elements\RegisteredResource;
use PhpMcp\Server\Elements\RegisteredResourceTemplate;
use PhpMcp\Server\Elements\RegisteredTool;
use PhpMcp\Server\Registry;
use PhpMcp\Server\Utils\Discoverer;
use PhpMcp\Server\Utils\DocBlockParser;
use PhpMcp\Server\Utils\SchemaGenerator;
use PhpMcp\Server\Tests\Fixtures\General\CompletionProviderFixture;
use Psr\Log\NullLogger;

beforeEach(function () {
    $logger = new NullLogger();
    $this->registry = new Registry($logger);

    $docBlockParser = new DocBlockParser($logger);
    $schemaGenerator = new SchemaGenerator($docBlockParser);
    $this->discoverer = new Discoverer($this->registry, $logger, $docBlockParser, $schemaGenerator);

    $this->fixtureBasePath = realpath(__DIR__ . '/../Fixtures');
});

it('discovers all element types correctly from fixture files', function () {
    $scanDir = 'Discovery';

    $this->discoverer->discover($this->fixtureBasePath, [$scanDir]);

    // --- Assert Tools ---
    $tools = $this->registry->getTools();
    expect($tools)->toHaveCount(4); // greet_user, repeatAction, InvokableCalculator, hidden_subdir_tool

    $greetUserTool = $this->registry->getTool('greet_user');
    expect($greetUserTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($greetUserTool->isManual)->toBeFalse()
        ->and($greetUserTool->schema->name)->toBe('greet_user')
        ->and($greetUserTool->schema->description)->toBe('Greets a user by name.')
        ->and($greetUserTool->handlerClass)->toBe(\PhpMcp\Server\Tests\Fixtures\Discovery\DiscoverableToolHandler::class)
        ->and($greetUserTool->handlerMethod)->toBe('greet');
    expect($greetUserTool->schema->inputSchema['properties'] ?? [])->toHaveKey('name');

    $repeatActionTool = $this->registry->getTool('repeatAction');
    expect($repeatActionTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($repeatActionTool->isManual)->toBeFalse()
        ->and($repeatActionTool->schema->description)->toBe('A tool with more complex parameters and inferred name/description.')
        ->and($repeatActionTool->schema->annotations->readOnlyHint)->toBeTrue();
    expect(array_keys($repeatActionTool->schema->inputSchema['properties'] ?? []))->toEqual(['count', 'loudly', 'mode']);

    $invokableCalcTool = $this->registry->getTool('InvokableCalculator');
    expect($invokableCalcTool)->toBeInstanceOf(RegisteredTool::class)
        ->and($invokableCalcTool->isManual)->toBeFalse()
        ->and($invokableCalcTool->handlerClass)->toBe(\PhpMcp\Server\Tests\Fixtures\Discovery\InvocableToolFixture::class)
        ->and($invokableCalcTool->handlerMethod)->toBe('__invoke');

    expect($this->registry->getTool('private_tool_should_be_ignored'))->toBeNull();
    expect($this->registry->getTool('protected_tool_should_be_ignored'))->toBeNull();
    expect($this->registry->getTool('static_tool_should_be_ignored'))->toBeNull();


    // --- Assert Resources ---
    $resources = $this->registry->getResources();
    expect($resources)->toHaveCount(3); // app_version, ui_settings_discovered, InvocableResourceFixture

    $appVersionRes = $this->registry->getResource('app://info/version');
    expect($appVersionRes)->toBeInstanceOf(RegisteredResource::class)
        ->and($appVersionRes->isManual)->toBeFalse()
        ->and($appVersionRes->schema->name)->toBe('app_version')
        ->and($appVersionRes->schema->mimeType)->toBe('text/plain');

    $invokableStatusRes = $this->registry->getResource('invokable://config/status');
    expect($invokableStatusRes)->toBeInstanceOf(RegisteredResource::class)
        ->and($invokableStatusRes->isManual)->toBeFalse()
        ->and($invokableStatusRes->handlerClass)->toBe(\PhpMcp\Server\Tests\Fixtures\Discovery\InvocableResourceFixture::class)
        ->and($invokableStatusRes->handlerMethod)->toBe('__invoke');


    // --- Assert Prompts ---
    $prompts = $this->registry->getPrompts();
    expect($prompts)->toHaveCount(3); // creative_story_prompt, simpleQuestionPrompt, InvocablePromptFixture

    $storyPrompt = $this->registry->getPrompt('creative_story_prompt');
    expect($storyPrompt)->toBeInstanceOf(RegisteredPrompt::class)
        ->and($storyPrompt->isManual)->toBeFalse()
        ->and($storyPrompt->schema->arguments)->toHaveCount(2) // genre, lengthWords
        ->and($storyPrompt->getCompletionProvider('genre'))->toBe(CompletionProviderFixture::class);

    $simplePrompt = $this->registry->getPrompt('simpleQuestionPrompt'); // Inferred name
    expect($simplePrompt)->toBeInstanceOf(RegisteredPrompt::class)
        ->and($simplePrompt->isManual)->toBeFalse();

    $invokableGreeter = $this->registry->getPrompt('InvokableGreeterPrompt');
    expect($invokableGreeter)->toBeInstanceOf(RegisteredPrompt::class)
        ->and($invokableGreeter->isManual)->toBeFalse()
        ->and($invokableGreeter->handlerClass)->toBe(\PhpMcp\Server\Tests\Fixtures\Discovery\InvocablePromptFixture::class);


    // --- Assert Resource Templates ---
    $templates = $this->registry->getResourceTemplates();
    expect($templates)->toHaveCount(3); // product_details_template, getFileContent, InvocableResourceTemplateFixture

    $productTemplate = $this->registry->getResourceTemplate('product://{region}/details/{productId}');
    expect($productTemplate)->toBeInstanceOf(RegisteredResourceTemplate::class)
        ->and($productTemplate->isManual)->toBeFalse()
        ->and($productTemplate->schema->name)->toBe('product_details_template')
        ->and($productTemplate->getCompletionProvider('region'))->toBe(CompletionProviderFixture::class);
    expect($productTemplate->getVariableNames())->toEqualCanonicalizing(['region', 'productId']);

    $invokableUserTemplate = $this->registry->getResourceTemplate('invokable://user-profile/{userId}');
    expect($invokableUserTemplate)->toBeInstanceOf(RegisteredResourceTemplate::class)
        ->and($invokableUserTemplate->isManual)->toBeFalse()
        ->and($invokableUserTemplate->handlerClass)->toBe(\PhpMcp\Server\Tests\Fixtures\Discovery\InvocableResourceTemplateFixture::class);
});

it('does not discover elements from excluded directories', function () {
    $this->discoverer->discover($this->fixtureBasePath, ['Discovery']);

    expect($this->registry->getTool('hidden_subdir_tool'))->not->toBeNull();

    $this->registry->clear();

    $this->discoverer->discover($this->fixtureBasePath, ['Discovery'], ['SubDir']);
    expect($this->registry->getTool('hidden_subdir_tool'))->toBeNull();
});

it('handles empty directories or directories with no PHP files', function () {
    $this->discoverer->discover($this->fixtureBasePath, ['EmptyDir']);
    expect($this->registry->getTools())->toBeEmpty();
});

it('correctly infers names and descriptions from methods/classes if not set in attribute', function () {
    $this->discoverer->discover($this->fixtureBasePath, ['Discovery']);

    $repeatActionTool = $this->registry->getTool('repeatAction');
    expect($repeatActionTool->schema->name)->toBe('repeatAction'); // Method name
    expect($repeatActionTool->schema->description)->toBe('A tool with more complex parameters and inferred name/description.'); // Docblock summary

    $simplePrompt = $this->registry->getPrompt('simpleQuestionPrompt');
    expect($simplePrompt->schema->name)->toBe('simpleQuestionPrompt');
    expect($simplePrompt->schema->description)->toBeNull();

    $invokableCalc = $this->registry->getTool('InvokableCalculator'); // Name comes from Attr
    expect($invokableCalc->schema->name)->toBe('InvokableCalculator');
    expect($invokableCalc->schema->description)->toBe('An invokable calculator tool.');
});
