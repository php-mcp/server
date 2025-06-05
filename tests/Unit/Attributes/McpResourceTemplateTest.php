<?php

namespace PhpMcp\Server\Tests\Unit\Attributes;

use PhpMcp\Server\Attributes\McpResourceTemplate;

it('instantiates with correct properties', function () {
    // Arrange
    $uriTemplate = 'file:///{path}/data';
    $name = 'test-template-name';
    $description = 'This is a test template description.';
    $mimeType = 'application/json';

    // Act
    $attribute = new McpResourceTemplate(
        uriTemplate: $uriTemplate,
        name: $name,
        description: $description,
        mimeType: $mimeType,
    );

    // Assert
    expect($attribute->uriTemplate)->toBe($uriTemplate);
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
    expect($attribute->mimeType)->toBe($mimeType);
});

it('instantiates with null values for name and description', function () {
    // Arrange & Act
    $attribute = new McpResourceTemplate(
        uriTemplate: 'test://{id}', // uriTemplate is required
        name: null,
        description: null,
        mimeType: null,
    );

    // Assert
    expect($attribute->uriTemplate)->toBe('test://{id}');
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->mimeType)->toBeNull();
});

it('instantiates with missing optional arguments', function () {
    // Arrange & Act
    $uriTemplate = 'tmpl://{key}';
    $attribute = new McpResourceTemplate(uriTemplate: $uriTemplate);

    // Assert
    expect($attribute->uriTemplate)->toBe($uriTemplate);
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->mimeType)->toBeNull();
});
