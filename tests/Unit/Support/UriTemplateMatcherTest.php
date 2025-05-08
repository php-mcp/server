<?php

namespace PhpMcp\Server\Tests\Unit\Support;

use PhpMcp\Server\Support\UriTemplateMatcher;

// Test basic Level 1 template matching

test('matches simple variable template', function (string $template, string $uri, ?array $expectedVariables) {
    $matcher = new UriTemplateMatcher($template);
    $variables = $matcher->match($uri);
    expect($variables)->toEqual($expectedVariables);
})->with([
    ['user://{userId}', 'user://12345', ['userId' => '12345']],
    ['user://{userId}', 'user://abc-def', ['userId' => 'abc-def']],
    ['user://{userId}', 'user://', null], // Missing variable part
    ['user://{userId}', 'users://12345', null], // Wrong scheme/path start
    ['item/{itemId}', 'item/xyz', ['itemId' => 'xyz']], // No scheme
    ['item/{itemId}', 'item/', null],
    ['{onlyVar}', 'anything', ['onlyVar' => 'anything']],
    ['{onlyVar}', '', null],
]);

test('matches multi-variable template', function (string $template, string $uri, ?array $expectedVariables) {
    $matcher = new UriTemplateMatcher($template);
    $variables = $matcher->match($uri);
    expect($variables)->toEqual($expectedVariables);
})->with([
    [
        'item/{category}/{itemId}/details',
        'item/books/978-abc/details',
        ['category' => 'books', 'itemId' => '978-abc'],
    ],
    [
        'item/{category}/{itemId}/details',
        'item/books//details', // Empty itemId segment
        null, // Currently matches [^/]+, so empty segment fails
    ],
    [
        'item/{category}/{itemId}/details',
        'item/books/978-abc/summary', // Wrong literal end
        null,
    ],
    [
        'item/{category}/{itemId}',
        'item/tools/hammer',
        ['category' => 'tools', 'itemId' => 'hammer'],
    ],
    [
        'item/{category}/{itemId}',
        'item/tools/hammer/extra', // Extra path segment
        null,
    ],
]);

test('matches template with literals and variables mixed', function (string $template, string $uri, ?array $expectedVariables) {
    $matcher = new UriTemplateMatcher($template);
    $variables = $matcher->match($uri);
    expect($variables)->toEqual($expectedVariables);
})->with([
    [
        'user://{userId}/profile/pic_{picId}.jpg',
        'user://kp/profile/pic_main.jpg',
        ['userId' => 'kp', 'picId' => 'main'],
    ],
    [
        'user://{userId}/profile/pic_{picId}.jpg',
        'user://kp/profile/pic_main.png', // Wrong extension
        null,
    ],
    [
        'user://{userId}/profile/img_{picId}.jpg', // Wrong literal prefix
        'user://kp/profile/pic_main.jpg',
        null,
    ],
]);

test('matches template with no variables', function (string $template, string $uri, ?array $expectedVariables) {
    $matcher = new UriTemplateMatcher($template);
    $variables = $matcher->match($uri);
    // Expect empty array on match, null otherwise
    if ($expectedVariables !== null) {
        expect($variables)->toBeArray()->toBeEmpty();
    } else {
        expect($variables)->toBeNull();
    }

})->with([
    ['config://settings/app', 'config://settings/app', []],
    ['config://settings/app', 'config://settings/user', null],
    ['/path/to/resource', '/path/to/resource', []],
    ['/path/to/resource', '/path/to/other', null],
]);

test('handles characters needing escaping in literals', function () {
    // Characters like . ? * + ( ) [ ] | are escaped by preg_quote
    $template = 'search/{query}/results.json?page={pageNo}';
    $matcher = new UriTemplateMatcher($template);

    $variables = $matcher->match('search/term.with.dots/results.json?page=2');
    expect($variables)->toEqual(['query' => 'term.with.dots', 'pageNo' => '2']);

    $noMatch = $matcher->match('search/term/results.xml?page=1'); // Wrong literal extension
    expect($noMatch)->toBeNull();
});

test('constructor compiles regex', function () {
    $template = 'test/{id}/value';
    $matcher = new UriTemplateMatcher($template);

    // Use reflection to check the compiled regex (optional, implementation detail)
    $reflection = new \ReflectionClass($matcher);
    $regexProp = $reflection->getProperty('regex');
    $regexProp->setAccessible(true);
    $compiledRegex = $regexProp->getValue($matcher);

    // Expected regex: starts with delimiter, ^, literals escaped, var replaced, $, delimiter
    // Example: '#^test\/(?P<id>[^/]+)\/value$#'
    expect($compiledRegex)->toBeString()->toContain('^test/')
        ->toContain('(?P<id>[^/]+)')
        ->toContain('/value$');
});
