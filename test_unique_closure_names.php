<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpMcp\Server\ServerBuilder;
use PhpMcp\Schema\Content\TextContent;

echo "🧪 Testing Unique Closure Names\n";
echo "=" . str_repeat("=", 35) . "\n\n";

// Create multiple closure tools without explicit names
$addTool = function (int $a, int $b): string {
    return "Sum: " . ($a + $b);
};

$multiplyTool = function (int $x, int $y): string {
    return "Product: " . ($x * $y);
};

$subtractTool = function (int $first, int $second): string {
    return "Difference: " . ($first - $second);
};

// Create multiple closure prompts without explicit names
$mathPrompt = function (string $operation): array {
    return [
        \PhpMcp\Schema\Content\PromptMessage::make(
            \PhpMcp\Schema\Enum\Role::User,
            new TextContent("Solve this $operation problem")
        )
    ];
};

$codePrompt = function (string $language): array {
    return [
        \PhpMcp\Schema\Content\PromptMessage::make(
            \PhpMcp\Schema\Enum\Role::User,
            new TextContent("Write code in $language")
        )
    ];
};

// Build server with multiple closures
$server = (new ServerBuilder())
    ->withServerInfo('UniqueNameTest', '1.0.0')
    // Tools without explicit names - should get unique names
    ->withTool($addTool)
    ->withTool($multiplyTool)
    ->withTool($subtractTool)
    // Prompts without explicit names - should get unique names
    ->withPrompt($mathPrompt)
    ->withPrompt($codePrompt)
    ->build();

echo "✅ Server built successfully with multiple unnamed closures!\n\n";

// Get the registry using reflection
$registryProperty = new ReflectionProperty($server, 'registry');
$registryProperty->setAccessible(true);
$registry = $registryProperty->getValue($server);

// Check tool names
echo "🔧 Registered Tool Names:\n";
echo "-" . str_repeat("-", 25) . "\n";
$tools = $registry->getTools();
foreach ($tools as $name => $tool) {
    echo "  - $name: {$tool->description}\n";
}

// Check prompt names  
echo "\n💬 Registered Prompt Names:\n";
echo "-" . str_repeat("-", 27) . "\n";
$prompts = $registry->getPrompts();
foreach ($prompts as $name => $prompt) {
    echo "  - $name: {$prompt->description}\n";
}

// Verify uniqueness
echo "\n📊 Uniqueness Check:\n";
echo "-" . str_repeat("-", 20) . "\n";
$toolNames = array_keys($tools);
$promptNames = array_keys($prompts);
$allNames = array_merge($toolNames, $promptNames);

$uniqueNames = array_unique($allNames);
$totalNames = count($allNames);
$uniqueCount = count($uniqueNames);

echo "Total names: $totalNames\n";
echo "Unique names: $uniqueCount\n";

if ($totalNames === $uniqueCount) {
    echo "✅ All names are unique!\n";
} else {
    echo "❌ Found duplicate names!\n";
    $duplicates = array_diff_assoc($allNames, $uniqueNames);
    foreach ($duplicates as $duplicate) {
        echo "   Duplicate: $duplicate\n";
    }
}

// Test that the same closure gets the same name consistently
echo "\n🔄 Consistency Check:\n";
echo "-" . str_repeat("-", 20) . "\n";

$sameClosure = function (string $msg): string {
    return "Echo: $msg";
};

$server1 = (new ServerBuilder())
    ->withServerInfo('Test1', '1.0.0')
    ->withTool($sameClosure)
    ->build();

$server2 = (new ServerBuilder())
    ->withServerInfo('Test2', '1.0.0')
    ->withTool($sameClosure)
    ->build();

// Get names from both servers
$registry1Property = new ReflectionProperty($server1, 'registry');
$registry1Property->setAccessible(true);
$registry1 = $registry1Property->getValue($server1);

$registry2Property = new ReflectionProperty($server2, 'registry');
$registry2Property->setAccessible(true);
$registry2 = $registry2Property->getValue($server2);

$tools1 = $registry1->getTools();
$tools2 = $registry2->getTools();

$name1 = array_keys($tools1)[0];
$name2 = array_keys($tools2)[0];

echo "Same closure in server 1: $name1\n";
echo "Same closure in server 2: $name2\n";

if ($name1 === $name2) {
    echo "✅ Same closure gets consistent name!\n";
} else {
    echo "❌ Same closure gets different names!\n";
}

echo "\n🎉 Unique naming test complete!\n";
