<?php

namespace PhpMcp\Server\Tests\Unit\Attributes;

use PhpMcp\Server\Attributes\McpTool;

it('instantiates with correct properties', function () {
    // Arrange
    $name = 'test-tool-name';
    $description = 'This is a test description.';

    // Act
    $attribute = new McpTool(name: $name, description: $description);

    // Assert
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
});

it('instantiates with null values for name and description', function () {
    // Arrange & Act
    $attribute = new McpTool(name: null, description: null);

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});

it('instantiates with missing optional arguments', function () {
    // Arrange & Act
    $attribute = new McpTool(); // Use default constructor values

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
});
