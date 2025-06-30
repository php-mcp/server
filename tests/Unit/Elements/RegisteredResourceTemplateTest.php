<?php

namespace PhpMcp\Server\Tests\Unit\Elements;

use Mockery;
use PhpMcp\Schema\ResourceTemplate;
use PhpMcp\Server\Elements\RegisteredResourceTemplate;
use PhpMcp\Schema\Content\TextResourceContents;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use PhpMcp\Server\Tests\Fixtures\General\CompletionProviderFixture;
use Psr\Container\ContainerInterface;
use PhpMcp\Schema\Annotations;

beforeEach(function () {
    $this->container = Mockery::mock(ContainerInterface::class);
    $this->handlerInstance = new ResourceHandlerFixture();
    $this->container->shouldReceive('get')
        ->with(ResourceHandlerFixture::class)
        ->andReturn($this->handlerInstance)
        ->byDefault();

    $this->templateUri = 'item://{category}/{itemId}/details';
    $this->resourceTemplateSchema = ResourceTemplate::make(
        $this->templateUri,
        'item-details-template',
        mimeType: 'application/json'
    );

    $this->defaultHandlerMethod = 'getUserDocument';
    $this->matchingTemplateSchema = ResourceTemplate::make(
        'user://{userId}/doc/{documentId}',
        'user-doc-template',
        mimeType: 'application/json'
    );
});

it('constructs correctly with schema, handler, and completion providers', function () {
    $completionProviders = [
        'userId' => CompletionProviderFixture::class,
        'documentId' => 'Another\ProviderClass'
    ];

    $schema = ResourceTemplate::make(
        'user://{userId}/doc/{documentId}',
        'user-doc-template',
        mimeType: 'application/json'
    );

    $template = RegisteredResourceTemplate::make(
        schema: $schema,
        handler: [ResourceHandlerFixture::class, 'getUserDocument'],
        completionProviders: $completionProviders
    );

    expect($template->schema)->toBe($schema);
    expect($template->handler)->toBe([ResourceHandlerFixture::class, 'getUserDocument']);
    expect($template->isManual)->toBeFalse();
    expect($template->completionProviders)->toEqual($completionProviders);
    expect($template->completionProviders['userId'])->toBe(CompletionProviderFixture::class);
    expect($template->completionProviders['documentId'])->toBe('Another\ProviderClass');
    expect($template->completionProviders)->not->toHaveKey('nonExistentVar');
});

it('can be made as a manual registration', function () {
    $schema = ResourceTemplate::make(
        'user://{userId}/doc/{documentId}',
        'user-doc-template',
        mimeType: 'application/json'
    );

    $manualTemplate = RegisteredResourceTemplate::make(
        schema: $schema,
        handler: [ResourceHandlerFixture::class, 'getUserDocument'],
        isManual: true
    );

    expect($manualTemplate->isManual)->toBeTrue();
});

dataset('uri_template_matching_cases', [
    'simple_var'        => ['user://{userId}', 'user://12345', ['userId' => '12345']],
    'simple_var_alpha'  => ['user://{userId}', 'user://abc-def', ['userId' => 'abc-def']],
    'no_match_missing_var_part' => ['user://{userId}', 'user://', null],
    'no_match_prefix'   => ['user://{userId}', 'users://12345', null],
    'multi_var'         => ['item://{category}/{itemId}/details', 'item://books/978-abc/details', ['category' => 'books', 'itemId' => '978-abc']],
    'multi_var_empty_segment_fail' => ['item://{category}/{itemId}/details', 'item://books//details', null], // [^/]+ fails on empty segment
    'multi_var_wrong_literal_end' => ['item://{category}/{itemId}/details', 'item://books/978-abc/summary', null],
    'multi_var_no_suffix_literal' => ['item://{category}/{itemId}', 'item://tools/hammer', ['category' => 'tools', 'itemId' => 'hammer']],
    'multi_var_extra_segment_fail' => ['item://{category}/{itemId}', 'item://tools/hammer/extra', null],
    'mixed_literals_vars' => ['user://{userId}/profile/pic_{picId}.jpg', 'user://kp/profile/pic_main.jpg', ['userId' => 'kp', 'picId' => 'main']],
    'mixed_wrong_extension' => ['user://{userId}/profile/pic_{picId}.jpg', 'user://kp/profile/pic_main.png', null],
    'mixed_wrong_literal_prefix' => ['user://{userId}/profile/img_{picId}.jpg', 'user://kp/profile/pic_main.jpg', null],
    'escapable_chars_in_literal' => ['search://{query}/results.json?page={pageNo}', 'search://term.with.dots/results.json?page=2', ['query' => 'term.with.dots', 'pageNo' => '2']],
]);

it('matches URIs against template and extracts variables correctly', function (string $templateString, string $uriToTest, ?array $expectedVariables) {
    $schema = ResourceTemplate::make($templateString, 'test-match');
    $template = RegisteredResourceTemplate::make($schema, [ResourceHandlerFixture::class, 'getUserDocument']);

    if ($expectedVariables !== null) {
        expect($template->matches($uriToTest))->toBeTrue();
        $reflection = new \ReflectionClass($template);
        $prop = $reflection->getProperty('uriVariables');
        $prop->setAccessible(true);
        expect($prop->getValue($template))->toEqual($expectedVariables);
    } else {
        expect($template->matches($uriToTest))->toBeFalse();
    }
})->with('uri_template_matching_cases');

it('gets variable names from compiled template', function () {
    $schema = ResourceTemplate::make('foo://{varA}/bar/{varB_ext}.{format}', 'vars-test');
    $template = RegisteredResourceTemplate::make($schema, [ResourceHandlerFixture::class, 'getUserDocument']);
    expect($template->getVariableNames())->toEqualCanonicalizing(['varA', 'varB_ext', 'format']);
});

it('reads resource using handler with extracted URI variables', function () {
    $uriTemplate = 'item://{category}/{itemId}?format={format}';
    $uri = 'item://electronics/tv-123?format=json_pretty';
    $schema = ResourceTemplate::make($uriTemplate, 'item-details-template');
    $template = RegisteredResourceTemplate::make($schema, [ResourceHandlerFixture::class, 'getTemplatedContent']);

    expect($template->matches($uri))->toBeTrue();

    $resultContents = $template->read($this->container, $uri);

    expect($resultContents)->toBeArray()->toHaveCount(1);

    $content = $resultContents[0];
    expect($content)->toBeInstanceOf(TextResourceContents::class);
    expect($content->uri)->toBe($uri);
    expect($content->mimeType)->toBe('application/json');

    $decodedText = json_decode($content->text, true);
    expect($decodedText['message'])->toBe("Content for item tv-123 in category electronics, format json_pretty.");
    expect($decodedText['category_received'])->toBe('electronics');
    expect($decodedText['itemId_received'])->toBe('tv-123');
    expect($decodedText['format_received'])->toBe('json_pretty');
});

it('uses mimeType from schema if handler result does not specify one', function () {
    $uriTemplate = 'item://{category}/{itemId}?format={format}';
    $uri = 'item://books/bestseller?format=json_pretty';
    $schema = ResourceTemplate::make($uriTemplate, 'test-mime', mimeType: 'application/vnd.custom-template-xml');
    $template = RegisteredResourceTemplate::make($schema, [ResourceHandlerFixture::class, 'getTemplatedContent']);
    expect($template->matches($uri))->toBeTrue();

    $resultContents = $template->read($this->container, $uri);
    expect($resultContents[0]->mimeType)->toBe('application/vnd.custom-template-xml');
});

it('formats a simple string result from handler correctly for template', function () {
    $uri = 'item://tools/hammer';
    $schema = ResourceTemplate::make('item://{type}/{name}', 'test-simple-string', mimeType: 'text/x-custom');
    $template = RegisteredResourceTemplate::make($schema, [ResourceHandlerFixture::class, 'returnStringText']);
    expect($template->matches($uri))->toBeTrue();

    $mockHandler = Mockery::mock(ResourceHandlerFixture::class);
    $mockHandler->shouldReceive('returnStringText')->with($uri)->once()->andReturn('Simple content from template handler');
    $this->container->shouldReceive('get')->with(ResourceHandlerFixture::class)->andReturn($mockHandler);

    $resultContents = $template->read($this->container, $uri);
    expect($resultContents[0])->toBeInstanceOf(TextResourceContents::class)
        ->and($resultContents[0]->text)->toBe('Simple content from template handler')
        ->and($resultContents[0]->mimeType)->toBe('text/x-custom'); // From schema
});

it('propagates exceptions from handler during read', function () {
    $uri = 'item://tools/hammer';
    $schema = ResourceTemplate::make('item://{type}/{name}', 'test-simple-string', mimeType: 'text/x-custom');
    $template = RegisteredResourceTemplate::make($schema, [ResourceHandlerFixture::class, 'handlerThrowsException']);
    expect($template->matches($uri))->toBeTrue();
    $template->read($this->container, $uri);
})->throws(\DomainException::class, "Cannot read resource");

it('can be serialized to array and deserialized', function () {
    $schema = ResourceTemplate::make(
        'obj://{type}/{id}',
        'my-template',
        mimeType: 'application/template+json',
        annotations: Annotations::make(priority: 0.7)
    );

    $providers = ['type' => CompletionProviderFixture::class];
    $serializedProviders = ['type' => serialize(CompletionProviderFixture::class)];

    $original = RegisteredResourceTemplate::make(
        $schema,
        [ResourceHandlerFixture::class, 'getUserDocument'],
        true,
        $providers
    );

    $array = $original->toArray();

    expect($array['schema']['uriTemplate'])->toBe('obj://{type}/{id}');
    expect($array['schema']['name'])->toBe('my-template');
    expect($array['schema']['mimeType'])->toBe('application/template+json');
    expect($array['schema']['annotations']['priority'])->toBe(0.7);
    expect($array['handler'])->toBe([ResourceHandlerFixture::class, 'getUserDocument']);
    expect($array['isManual'])->toBeTrue();
    expect($array['completionProviders'])->toEqual($serializedProviders);

    $rehydrated = RegisteredResourceTemplate::fromArray($array);
    expect($rehydrated)->toBeInstanceOf(RegisteredResourceTemplate::class);
    expect($rehydrated->schema->uriTemplate)->toEqual($original->schema->uriTemplate);
    expect($rehydrated->schema->name)->toEqual($original->schema->name);
    expect($rehydrated->isManual)->toBeTrue();
    expect($rehydrated->completionProviders)->toEqual($providers);
});

it('fromArray returns false on failure', function () {
    $badData = ['schema' => ['uriTemplate' => 'fail']];
    expect(RegisteredResourceTemplate::fromArray($badData))->toBeFalse();
});
