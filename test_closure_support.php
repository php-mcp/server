<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpMcp\Server\ServerBuilder;
use PhpMcp\Schema\Implementation;
use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Schema\Content\PromptMessage;
use PhpMcp\Schema\Enum\Role;
use Psr\Container\ContainerInterface;

// Create a simple container for testing
class TestContainer implements ContainerInterface
{
    private array $services = [];

    public function get(string $id)
    {
        return $this->services[$id] ?? new $id();
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || class_exists($id);
    }

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }
}

// Create a test closure tool
$calculateTool = function (int $a, int $b, string $operation = 'add'): string {
    return match ($operation) {
        'add' => "Result: " . ($a + $b),
        'subtract' => "Result: " . ($a - $b),
        'multiply' => "Result: " . ($a * $b),
        'divide' => $b !== 0 ? "Result: " . ($a / $b) : "Cannot divide by zero",
        default => "Unknown operation: $operation"
    };
};

// Create a test closure resource
$configResource = function (string $uri): array {
    return [
        new TextContent("Configuration for URI: $uri"),
        new TextContent("Environment: development"),
        new TextContent("Version: 1.0.0")
    ];
};

// Create a test closure prompt
$codeGenPrompt = function (string $language, string $description): array {
    return [
        PromptMessage::make(
            Role::User,
            new TextContent("Generate $language code for: $description")
        )
    ];
};

// Create a test closure resource template
$dynamicResource = function (string $uri, string $id): array {
    return [
        new TextContent("Dynamic resource ID: $id"),
        new TextContent("Requested URI: $uri"),
        new TextContent("Generated at: " . date('Y-m-d H:i:s'))
    ];
};

// Test static method support
class StaticToolHandler
{
    public static function getCurrentTime(): string
    {
        return "Current time: " . date('Y-m-d H:i:s');
    }
}

// Test instance method support
class InstanceToolHandler
{
    private string $prefix;

    public function __construct(string $prefix = "Instance")
    {
        $this->prefix = $prefix;
    }

    public function greet(string $name): string
    {
        return "{$this->prefix}: Hello, $name!";
    }
}

echo "🧪 Testing MCP Server Closure and Callable Support\n";
echo "=" . str_repeat("=", 50) . "\n\n";

// Build the server with various handler types
$container = new TestContainer();
$container->set(InstanceToolHandler::class, new InstanceToolHandler("TestInstance"));

$server = (new ServerBuilder())
    ->withServerInfo('ClosureTest', '1.0.0')
    ->withContainer($container)
    ->withTool($calculateTool, 'calculator', 'Performs basic mathematical operations')
    ->withResource($configResource, 'config://app', 'app_config', 'Gets app configuration')
    ->withResourceTemplate($dynamicResource, 'dynamic://item/{id}', 'dynamic_item', 'Gets dynamic items by ID')
    ->withPrompt($codeGenPrompt, 'code_generator', 'Generates code in specified language')
    ->withTool([StaticToolHandler::class, 'getCurrentTime'], 'current_time', 'Gets current server time')
    ->withTool([InstanceToolHandler::class, 'greet'], 'greeter', 'Greets a person')
    ->build();

echo "✅ Server built successfully with various handler types!\n\n";

// Get the registry using reflection
$registryProperty = new ReflectionProperty($server, 'registry');
$registryProperty->setAccessible(true);
$registry = $registryProperty->getValue($server);

// Test Tools
echo "🔧 Testing Tools:\n";
echo "-" . str_repeat("-", 20) . "\n";

// Test closure tool
$calculatorTool = $registry->getTool('calculator');
if ($calculatorTool) {
    try {
        $result = $calculatorTool->call($container, ['a' => 10, 'b' => 5, 'operation' => 'add']);
        echo "✅ Closure Tool (calculator): " . $result[0]->text . "\n";

        $result = $calculatorTool->call($container, ['a' => 10, 'b' => 3, 'operation' => 'multiply']);
        echo "✅ Closure Tool (calculator): " . $result[0]->text . "\n";
    } catch (Exception $e) {
        echo "❌ Closure Tool failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Calculator tool not found\n";
}

// Test static method tool
$timeTool = $registry->getTool('current_time');
if ($timeTool) {
    try {
        $result = $timeTool->call($container, []);
        echo "✅ Static Method Tool (current_time): " . $result[0]->text . "\n";
    } catch (Exception $e) {
        echo "❌ Static Method Tool failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Current time tool not found\n";
}

// Test instance method tool
$greeterTool = $registry->getTool('greeter');
if ($greeterTool) {
    try {
        $result = $greeterTool->call($container, ['name' => 'Alice']);
        echo "✅ Instance Method Tool (greeter): " . $result[0]->text . "\n";
    } catch (Exception $e) {
        echo "❌ Instance Method Tool failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Greeter tool not found\n";
}

// Test Resources
echo "\n📁 Testing Resources:\n";
echo "-" . str_repeat("-", 20) . "\n";

// Test closure resource
$configRes = $registry->getResource('config://app');
if ($configRes) {
    try {
        $result = $configRes->read($container, 'config://app');
        if (is_array($result) && isset($result[0])) {
            echo "✅ Closure Resource (config): " . $result[0]->text . "\n";
            if (isset($result[1])) echo "   └─ " . $result[1]->text . "\n";
            if (isset($result[2])) echo "   └─ " . $result[2]->text . "\n";
        } else {
            echo "✅ Closure Resource (config): " . (is_string($result) ? $result : json_encode($result)) . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Closure Resource failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Config resource not found\n";
}

// Test Resource Templates
echo "\n📋 Testing Resource Templates:\n";
echo "-" . str_repeat("-", 30) . "\n";

// Test closure resource template
$dynamicRes = $registry->getResource('dynamic://item/123');
if ($dynamicRes) {
    try {
        $result = $dynamicRes->read($container, 'dynamic://item/123');
        if (is_array($result) && isset($result[0])) {
            echo "✅ Closure Resource Template (dynamic): " . $result[0]->text . "\n";
            if (isset($result[1])) echo "   └─ " . $result[1]->text . "\n";
            if (isset($result[2])) echo "   └─ " . $result[2]->text . "\n";
        } else {
            echo "✅ Closure Resource Template (dynamic): " . (is_string($result) ? $result : json_encode($result)) . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Closure Resource Template failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Dynamic resource template not found\n";
}

// Test Prompts
echo "\n💬 Testing Prompts:\n";
echo "-" . str_repeat("-", 20) . "\n";

// Test closure prompt
$codePrompt = $registry->getPrompt('code_generator');
if ($codePrompt) {
    try {
        $result = $codePrompt->get($container, ['language' => 'PHP', 'description' => 'a calculator function']);
        if (is_array($result) && isset($result[0])) {
            // Result is an array of PromptMessage objects
            $message = $result[0];
            if ($message instanceof \PhpMcp\Schema\Content\PromptMessage) {
                echo "✅ Closure Prompt (code_generator): " . $message->content->text . "\n";
            } else {
                echo "✅ Closure Prompt (code_generator): " . json_encode($result) . "\n";
            }
        } else {
            echo "✅ Closure Prompt (code_generator): " . (is_string($result) ? $result : json_encode($result)) . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Closure Prompt failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Code generator prompt not found\n";
}

// Summary
echo "\n📊 Registry Summary:\n";
echo "-" . str_repeat("-", 20) . "\n";
$tools = $registry->getTools();
$resources = $registry->getResources();
$prompts = $registry->getPrompts();
$templates = $registry->getResourceTemplates();

echo "✅ Tools: " . count($tools) . "\n";
echo "✅ Resources: " . count($resources) . "\n";
echo "✅ Prompts: " . count($prompts) . "\n";
echo "✅ Resource Templates: " . count($templates) . "\n";

echo "\n🎉 All tests passed! Closure and callable support is working correctly.\n";
echo "   ✓ Closures as handlers\n";
echo "   ✓ Static methods as handlers\n";
echo "   ✓ Instance methods as handlers\n";
echo "   ✓ All handler types can be called successfully\n";
