<?php

namespace Tests\Attributes;

use PhpMcp\Server\Attributes\McpTool;

test('constructor assigns properties correctly for McpTool', function () {
    // Arrange
    $name = 'test-tool-name';
    $description = 'This is a test description.';

    // Act
    $attribute = new McpTool(name: $name, description: $description);

    // Assert
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
});

test('constructor handles null values for McpTool', function () {
    // Arrange & Act
    $attribute = new McpTool(name: null, description: null);

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});

test('constructor handles missing optional arguments for McpTool', function () {
    // Arrange & Act
    $attribute = new McpTool(); // Use default constructor values

    // Assert
    // Check default values (assuming they are null)
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});
