<?php

namespace Tests\Attributes;

use PhpMcp\Server\Attributes\McpResourceTemplate;

test('constructor assigns properties correctly for McpResourceTemplate', function () {
    // Arrange
    $uriTemplate = 'file:///{path}/data';
    $name = 'test-template-name';
    $description = 'This is a test template description.';
    $mimeType = 'application/json';
    $annotations = ['group' => 'files'];

    // Act
    $attribute = new McpResourceTemplate(
        uriTemplate: $uriTemplate,
        name: $name,
        description: $description,
        mimeType: $mimeType,
        annotations: $annotations
    );

    // Assert
    expect($attribute->uriTemplate)->toBe($uriTemplate);
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
    expect($attribute->mimeType)->toBe($mimeType);
    expect($attribute->annotations)->toBe($annotations);
});

test('constructor handles null values for McpResourceTemplate', function () {
    // Arrange & Act
    $attribute = new McpResourceTemplate(
        uriTemplate: 'test://{id}', // uriTemplate is required
        name: null,
        description: null,
        mimeType: null,
        annotations: []
    );

    // Assert
    expect($attribute->uriTemplate)->toBe('test://{id}');
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->mimeType)->toBeNull();
    expect($attribute->annotations)->toBe([]);
});

test('constructor handles missing optional arguments for McpResourceTemplate', function () {
    // Arrange & Act
    $uriTemplate = 'tmpl://{key}';
    $attribute = new McpResourceTemplate(uriTemplate: $uriTemplate);

    // Assert
    expect($attribute->uriTemplate)->toBe($uriTemplate);
    // Check default values (assuming they are null or empty array)
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->mimeType)->toBeNull();
    expect($attribute->annotations)->toBe([]);
});
