<?php

namespace PhpMcp\Server\Tests\Unit\Attributes;

use PhpMcp\Server\Attributes\McpPrompt;

it('instantiates with name and description', function () {
    // Arrange
    $name = 'test-prompt-name';
    $description = 'This is a test prompt description.';

    // Act
    $attribute = new McpPrompt(name: $name, description: $description);

    // Assert
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
});

it('instantiates with null values for name and description', function () {
    // Arrange & Act
    $attribute = new McpPrompt(name: null, description: null);

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});

it('instantiates with missing optional arguments', function () {
    // Arrange & Act
    $attribute = new McpPrompt(); // Use default constructor values

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});
