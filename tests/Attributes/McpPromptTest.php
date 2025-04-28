<?php

namespace Tests\Attributes;

use PhpMcp\Server\Attributes\McpPrompt;

test('constructor assigns properties correctly for McpPrompt', function () {
    // Arrange
    $name = 'test-prompt-name';
    $description = 'This is a test prompt description.';

    // Act
    $attribute = new McpPrompt(name: $name, description: $description);

    // Assert
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
});

test('constructor handles null values for McpPrompt', function () {
    // Arrange & Act
    $attribute = new McpPrompt(name: null, description: null);

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});

test('constructor handles missing optional arguments for McpPrompt', function () {
    // Arrange & Act
    $attribute = new McpPrompt(); // Use default constructor values

    // Assert
    // Check default values (assuming they are null)
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});
