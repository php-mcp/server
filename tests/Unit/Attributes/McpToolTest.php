<?php

namespace PhpMcp\Server\Tests\Unit\Attributes;

use PhpMcp\Server\Attributes\McpTool;

it('instantiates with correct properties', function () {
    // Arrange
    $name = 'test-tool-name';
    $description = 'This is a test description.';
    $annotations = ['title' => 'Test Tool', 'readOnlyHint' => true];

    // Act
    $attribute = new McpTool(name: $name, description: $description, annotations: $annotations);

    // Assert
    expect($attribute->name)->toBe($name);
    expect($attribute->description)->toBe($description);
    expect($attribute->annotations)->toBe($annotations);
});

it('instantiates with null values for name and description', function () {
    // Arrange & Act
    $attribute = new McpTool(name: null, description: null);

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->annotations)->toBe([]);
});

it('instantiates with missing optional arguments', function () {
    // Arrange & Act
    $attribute = new McpTool(); // Use default constructor values

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->annotations)->toBe([]);
});

it('instantiates with only annotations provided', function () {
    // Arrange
    $annotations = ['destructiveHint' => true, 'category' => 'admin'];

    // Act
    $attribute = new McpTool(annotations: $annotations);

    // Assert
    expect($attribute->name)->toBeNull();
    expect($attribute->description)->toBeNull();
    expect($attribute->annotations)->toBe($annotations);
});

it('instantiates with empty annotations array', function () {
    // Arrange & Act
    $attribute = new McpTool(name: 'test', description: 'test desc', annotations: []);

    // Assert
    expect($attribute->name)->toBe('test');
    expect($attribute->description)->toBe('test desc');
    expect($attribute->annotations)->toBe([]);
});
