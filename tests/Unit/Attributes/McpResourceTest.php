<?php

namespace PhpMcp\Server\Tests\Unit\Attributes;

use PhpMcp\Server\Attributes\McpResource;

test('constructor assigns properties correctly for McpResource', function () {
    // Arrange
    $uri = 'file:///test/resource';
    $name = 'test-resource-name';
    $description = 'This is a test resource description.';
    $mimeType = 'text/plain';
    $size = 1024;
    $annotations = ['priority' => 5];

    // Act
    $attribute = new McpResource(
        uri: $uri,
        name: $name,
        description: $description,
        mimeType: $mimeType,
        size: $size,
        annotations: $annotations
    );

    // Assert
    expect($attribute->uri)->toBe($uri);
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
    expect($attribute->mimeType)->toBe($mimeType);
    expect($attribute->size)->toBe($size);
    expect($attribute->annotations)->toBe($annotations);
});

test('constructor handles null values for McpResource', function () {
    // Arrange & Act
    $attribute = new McpResource(
        uri: 'file:///test', // URI is required
        name: null,
        description: null,
        mimeType: null,
        size: null,
        annotations: []
    );

    // Assert
    expect($attribute->uri)->toBe('file:///test');
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->mimeType)->toBeNull();
    expect($attribute->size)->toBeNull();
    expect($attribute->annotations)->toBe([]);
});

test('constructor handles missing optional arguments for McpResource', function () {
    // Arrange & Act
    $uri = 'file:///only-uri';
    $attribute = new McpResource(uri: $uri);

    // Assert
    expect($attribute->uri)->toBe($uri);
    // Check default values (assuming they are null or empty array)
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->mimeType)->toBeNull();
    expect($attribute->size)->toBeNull();
    expect($attribute->annotations)->toBe([]);
});
