# PHP MCP Server SDK

[![Latest Version on Packagist](https://img.shields.io/packagist/v/php-mcp/server.svg?style=flat-square)](https://packagist.org/packages/php-mcp/server)
[![Total Downloads](https://img.shields.io/packagist/dt/php-mcp/server.svg?style=flat-square)](https://packagist.org/packages/php-mcp/server)
[![Tests](https://img.shields.io/github/actions/workflow/status/php-mcp/server/tests.yml?branch=main&style=flat-square)](https://github.com/php-mcp/server/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/php-mcp/server.svg?style=flat-square)](LICENSE)

**A comprehensive PHP SDK for building [Model Context Protocol (MCP)](https://modelcontextprotocol.io/introduction) servers. Create production-ready MCP servers in PHP with modern architecture, extensive testing, and flexible transport options.**

This SDK enables you to expose your PHP application's functionality as standardized MCP **Tools**, **Resources**, and **Prompts**, allowing AI assistants (like Anthropic's Claude, Cursor IDE, OpenAI's ChatGPT, etc.) to interact with your backend using the MCP standard.

## 🚀 Key Features

- **🏗️ Modern Architecture**: Built with PHP 8.1+ features, PSR standards, and modular design
- **📡 Multiple Transports**: Supports `stdio`, `http+sse`, and new **streamable HTTP** with resumability
- **🎯 Attribute-Based Definition**: Use PHP 8 Attributes (`#[McpTool]`, `#[McpResource]`, etc.) for zero-config element registration
- **🔧 Flexible Handlers**: Support for closures, class methods, static methods, and invokable classes
- **📝 Smart Schema Generation**: Automatic JSON schema generation from method signatures with optional `#[Schema]` attribute enhancements
- **⚡ Session Management**: Advanced session handling with multiple storage backends
- **🔄 Event-Driven**: ReactPHP-based for high concurrency and non-blocking operations  
- **📊 Batch Processing**: Full support for JSON-RPC batch requests
- **💾 Smart Caching**: Intelligent caching of discovered elements with manual override precedence
- **🧪 Completion Providers**: Built-in support for argument completion in tools and prompts
- **🔌 Dependency Injection**: Full PSR-11 container support with auto-wiring
- **📋 Comprehensive Testing**: Extensive test suite with integration tests for all transports

This package supports the **2025-03-26** version of the Model Context Protocol with backward compatibility.

## 📋 Requirements

- **PHP** >= 8.1
- **Composer**
- **For HTTP Transport**: An event-driven PHP environment (CLI recommended)
- **Extensions**: `json`, `mbstring`, `pcre` (typically enabled by default)

## 📦 Installation

```bash
composer require php-mcp/server
```

> **💡 Laravel Users**: Consider using [`php-mcp/laravel`](https://github.com/php-mcp/laravel) for enhanced framework integration, configuration management, and Artisan commands.

## ⚡ Quick Start: Stdio Server with Discovery

This example demonstrates the most common usage pattern - a `stdio` server using attribute discovery.

**1. Define Your MCP Elements**

Create `src/CalculatorElements.php`:

```php
<?php

namespace App;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;

class CalculatorElements
{
    /**
     * Adds two numbers together.
     * 
     * @param int $a The first number
     * @param int $b The second number  
     * @return int The sum of the two numbers
     */
    #[McpTool(name: 'add_numbers')]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * Calculates power with validation.
     */
    #[McpTool(name: 'calculate_power')]
    public function power(
        #[Schema(type: 'number', minimum: 0, maximum: 1000)]
        float $base,
        
        #[Schema(type: 'integer', minimum: 0, maximum: 10)]
        int $exponent
    ): float {
        return pow($base, $exponent);
    }
}
```

**2. Create the Server Script**

Create `mcp-server.php`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

try {
    // Build server configuration
    $server = Server::make()
        ->withServerInfo('PHP Calculator Server', '1.0.0') 
        ->build();

    // Discover MCP elements via attributes
    $server->discover(
        basePath: __DIR__,
        scanDirs: ['src']
    );

    // Start listening via stdio transport
    $transport = new StdioServerTransport();
    $server->listen($transport);

} catch (\Throwable $e) {
    fwrite(STDERR, "[CRITICAL ERROR] " . $e->getMessage() . "\n");
    exit(1);
}
```

**3. Configure Your MCP Client**

Add to your client configuration (e.g., `.cursor/mcp.json`):

```json
{
    "mcpServers": {
        "php-calculator": {
            "command": "php",
            "args": ["/absolute/path/to/your/mcp-server.php"]
        }
    }
}
```

**4. Test the Server**

Your AI assistant can now call:
- `add_numbers` - Add two integers
- `calculate_power` - Calculate power with validation constraints

## 🏗️ Architecture Overview

The PHP MCP Server uses a modern, decoupled architecture:

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   MCP Client    │◄──►│   Transport      │◄──►│   Protocol      │
│  (Claude, etc.) │    │ (Stdio/HTTP/SSE) │    │   (JSON-RPC)    │
└─────────────────┘    └──────────────────┘    └─────────────────┘
                                                         │
                       ┌─────────────────┐               │
                       │ Session Manager │◄──────────────┤
                       │ (Multi-backend) │               │
                       └─────────────────┘               │
                                                         │
┌─────────────────┐    ┌──────────────────┐              │
│   Dispatcher    │◄───│   Server Core    │◄─────────────┤
│ (Method Router) │    │   Configuration  │              │
└─────────────────┘    └──────────────────┘              │
         │                                               │
         ▼                                               │
┌─────────────────┐    ┌──────────────────┐              │
│    Registry     │    │   Elements       │◄─────────────┘
│  (Element Store)│◄──►│ (Tools/Resources │
└─────────────────┘    │  Prompts/etc.)   │
                       └──────────────────┘
```

### Core Components

- **`ServerBuilder`**: Fluent configuration interface (`Server::make()->...->build()`)
- **`Server`**: Central coordinator containing all configured components
- **`Protocol`**: JSON-RPC 2.0 handler bridging transports and core logic
- **`SessionManager`**: Multi-backend session storage (array, cache, custom)
- **`Dispatcher`**: Method routing and request processing
- **`Registry`**: Element storage with smart caching and precedence rules
- **`Elements`**: Registered MCP components (Tools, Resources, Prompts, Templates)

### Transport Options

1. **`StdioServerTransport`**: Standard I/O for direct client launches
2. **`HttpServerTransport`**: HTTP + Server-Sent Events for web integration  
3. **`StreamableHttpServerTransport`**: Enhanced HTTP with resumability and event sourcing

## ⚙️ Server Configuration

### Basic Configuration

```php
use PhpMcp\Server\Server;
use PhpMcp\Schema\ServerCapabilities;

$server = Server::make()
    ->withServerInfo('My App Server', '2.1.0')
    ->withCapabilities(ServerCapabilities::make(
        resources: true,
        resourcesSubscribe: true,
        prompts: true,
        tools: true
    ))
    ->withPaginationLimit(100)
    ->build();
```

### Advanced Configuration with Dependencies

```php
use Psr\Log\Logger;
use Psr\SimpleCache\CacheInterface;
use Psr\Container\ContainerInterface;

$server = Server::make()
    ->withServerInfo('Production Server', '1.0.0')
    ->withLogger($myPsrLogger)                    // PSR-3 Logger
    ->withCache($myPsrCache)                      // PSR-16 Cache  
    ->withContainer($myPsrContainer)              // PSR-11 Container
    ->withSession('cache', 7200)                  // Cache-backed sessions, 2hr TTL
    ->withPaginationLimit(50)                     // Limit list responses
    ->build();
```

### Session Management Options

```php
// In-memory sessions (default, not persistent)
->withSession('array', 3600)

// Cache-backed sessions (persistent across restarts)  
->withSession('cache', 7200)

// Custom session handler (implement SessionHandlerInterface)
->withSessionHandler(new MyCustomSessionHandler(), 1800)
```

## 🎯 Defining MCP Elements

The server provides two powerful ways to define MCP elements: **Attribute-Based Discovery** (recommended) and **Manual Registration**. Both can be combined, with manual registrations taking precedence.

### Element Types

- **🔧 Tools**: Executable functions/actions (e.g., `calculate`, `send_email`, `query_database`)
- **📄 Resources**: Static content/data (e.g., `config://settings`, `file://readme.txt`)
- **📋 Resource Templates**: Dynamic resources with URI patterns (e.g., `user://{id}/profile`)  
- **💬 Prompts**: Conversation starters/templates (e.g., `summarize`, `translate`)

### 1. 🏷️ Attribute-Based Discovery (Recommended)

Use PHP 8 attributes to mark methods or invokable classes as MCP elements. The server will discover them via filesystem scanning.

```php
use PhpMcp\Server\Attributes\{McpTool, McpResource, McpResourceTemplate, McpPrompt};

class UserManager
{
    /**
     * Creates a new user account.
     */
    #[McpTool(name: 'create_user')]
    public function createUser(string $email, string $password, string $role = 'user'): array
    {
        // Create user logic
        return ['id' => 123, 'email' => $email, 'role' => $role];
    }

    /**
     * Get user configuration.
     */
    #[McpResource(
        uri: 'config://user/settings',
        mimeType: 'application/json'
    )]
    public function getUserConfig(): array
    {
        return ['theme' => 'dark', 'notifications' => true];
    }

    /**
     * Get user profile by ID.
     */
    #[McpResourceTemplate(
        uriTemplate: 'user://{userId}/profile',
        mimeType: 'application/json'
    )]
    public function getUserProfile(string $userId): array
    {
        return ['id' => $userId, 'name' => 'John Doe'];
    }

    /**
     * Generate welcome message prompt.
     */
    #[McpPrompt(name: 'welcome_user')]
    public function welcomeUserPrompt(string $username, string $role): array
    {
        return [
            ['role' => 'user', 'content' => "Create a welcome message for {$username} with role {$role}"]
        ];
    }
}
```

**Discovery Process:**

```php
// Build server first
$server = Server::make()
    ->withServerInfo('My App Server', '1.0.0')
    ->build();

// Then discover elements
$server->discover(
    basePath: __DIR__,
    scanDirs: ['src/Handlers', 'src/Services'],  // Directories to scan
    excludeDirs: ['src/Tests'],                  // Directories to skip
    saveToCache: true                            // Cache results (default: true)
);
```

**Available Attributes:**

- **`#[McpTool]`**: Executable actions
- **`#[McpResource]`**: Static content accessible via URI
- **`#[McpResourceTemplate]`**: Dynamic resources with URI templates  
- **`#[McpPrompt]`**: Conversation templates and prompt generators

### 2. 🔧 Manual Registration 

Register elements programmatically using the `ServerBuilder` before calling `build()`. Useful for dynamic registration, closures, or when you prefer explicit control.

```php
use App\Handlers\{EmailHandler, ConfigHandler, UserHandler, PromptHandler};
use PhpMcp\Schema\{ToolAnnotations, Annotations};

$server = Server::make()
    ->withServerInfo('Manual Registration Server', '1.0.0')
    
    // Register a tool with handler method
    ->withTool(
        [EmailHandler::class, 'sendEmail'],     // Handler: [class, method]
        name: 'send_email',                     // Tool name (optional)
        description: 'Send email to user',     // Description (optional)
        annotations: ToolAnnotations::make(     // Annotations (optional)
            title: 'Send Email Tool'
        )
    )
    
    // Register invokable class as tool
    ->withTool(UserHandler::class)             // Handler: Invokable class
    
    // Register a closure as tool
    ->withTool(
        function(int $a, int $b): int {         // Handler: Closure
            return $a + $b;
        },
        name: 'add_numbers',
        description: 'Add two numbers together'
    )
    
    // Register a resource with closure
    ->withResource(
        function(): array {                     // Handler: Closure
            return ['timestamp' => time(), 'server' => 'php-mcp'];
        },
        uri: 'config://runtime/status',         // URI (required)
        mimeType: 'application/json'           // MIME type (optional)
    )
    
    // Register a resource template
    ->withResourceTemplate(
        [UserHandler::class, 'getUserProfile'],
        uriTemplate: 'user://{userId}/profile'  // URI template (required)
    )
    
    // Register a prompt with closure
    ->withPrompt(
        function(string $topic, string $tone = 'professional'): array {
            return [
                ['role' => 'user', 'content' => "Write about {$topic} in a {$tone} tone"]
            ];
        },
        name: 'writing_prompt'                  // Prompt name (optional)
    )
    
    ->build();
```

The server supports three flexible handler formats: `[ClassName::class, 'methodName']` for class method handlers, `InvokableClass::class` for invokable class handlers (classes with `__invoke` method), and any PHP callable including closures, static methods like `[SomeClass::class, 'staticMethod']`, or function names. Class-based handlers are resolved via the configured PSR-11 container for dependency injection. Manual registrations are never cached and take precedence over discovered elements with the same identifier.

> [!IMPORTANT]
> When using closures as handlers, the server generates minimal JSON schemas based only on PHP type hints since there are no docblocks or class context available. For more detailed schemas with validation constraints, descriptions, and formats, you have two options:
> 
> - Use the [`#[Schema]` attribute](#-schema-generation-and-validation) for enhanced schema generation
> - Provide a custom `$inputSchema` parameter when registering tools with `->withTool()`

### 🏆 Element Precedence & Discovery

**Precedence Rules:**
- Manual registrations **always** override discovered/cached elements with the same identifier
- Discovered elements are cached for performance (configurable)
- Cache is automatically invalidated on fresh discovery runs

**Discovery Process:**

```php
$server->discover(
    basePath: __DIR__,
    scanDirs: ['src/Handlers', 'src/Services'],  // Scan these directories
    excludeDirs: ['tests', 'vendor'],            // Skip these directories
    force: false,                                // Force re-scan (default: false)
    saveToCache: true                            // Save to cache (default: true)
);
```

**Caching Behavior:**
- Only **discovered** elements are cached (never manual registrations)
- Cache loaded automatically during `build()` if available
- Fresh `discover()` calls clear and rebuild cache
- Use `force: true` to bypass discovery-already-ran check

## 🚀 Running the Server (Transports)

The server core is transport-agnostic. Choose a transport based on your deployment needs:

### 1. 📟 Stdio Transport

**Best for**: Direct client execution, command-line tools, simple deployments

```php
use PhpMcp\Server\Transports\StdioServerTransport;

$server = Server::make()
    ->withServerInfo('Stdio Server', '1.0.0')
    ->build();

$server->discover(__DIR__, ['src']);

// Create stdio transport (uses STDIN/STDOUT by default)
$transport = new StdioServerTransport();

// Start listening (blocking call)
$server->listen($transport);
```

**Client Configuration:**
```json
{
    "mcpServers": {
        "my-php-server": {
            "command": "php",
            "args": ["/absolute/path/to/server.php"]
        }
    }
}
```

> ⚠️ **Important**: When using stdio transport, **never** write to `STDOUT` in your handlers (use `STDERR` for debugging). `STDOUT` is reserved for JSON-RPC communication.

### 2. 🌐 HTTP + Server-Sent Events Transport (Deprecated)

> ⚠️ **Note**: This transport is deprecated in the latest MCP protocol version but remains available for backwards compatibility. For new projects, use the [StreamableHttpServerTransport](#3--streamable-http-transport-new) which provides enhanced features and better protocol compliance.

**Best for**: Legacy applications requiring backwards compatibility

```php
use PhpMcp\Server\Transports\HttpServerTransport;

$server = Server::make()
    ->withServerInfo('HTTP Server', '1.0.0')
    ->withLogger($logger)  // Recommended for HTTP
    ->build();

$server->discover(__DIR__, ['src']);

// Create HTTP transport
$transport = new HttpServerTransport(
    host: '127.0.0.1',      // MCP protocol prohibits 0.0.0.0
    port: 8080,             // Port number
    mcpPathPrefix: 'mcp'    // URL prefix (/mcp/sse, /mcp/message)
);

$server->listen($transport);
```

**Client Configuration:**
```json
{
    "mcpServers": {
        "my-http-server": {
            "url": "http://localhost:8080/mcp/sse"
        }
    }
}
```

**Endpoints:**
- **SSE Connection**: `GET /mcp/sse`
- **Message Sending**: `POST /mcp/message?clientId={clientId}`

### 3. 🔄 Streamable HTTP Transport (Recommended)

**Best for**: Production deployments, remote MCP servers, multiple clients, resumable connections

```php
use PhpMcp\Server\Transports\StreamableHttpServerTransport;

$server = Server::make()
    ->withServerInfo('Streamable Server', '1.0.0')
    ->withLogger($logger)
    ->withCache($cache)     // Required for resumability
    ->build();

$server->discover(__DIR__, ['src']);

// Create streamable transport with resumability
$transport = new StreamableHttpServerTransport(
    host: '127.0.0.1',      // MCP protocol prohibits 0.0.0.0
    port: 8080,
    mcpPathPrefix: 'mcp',
    enableJsonResponse: false,  // Use SSE streaming (default)
    stateless: false            // Enable stateless mode for session-less clients
);

$server->listen($transport);
```

**JSON Response Mode:**

The `enableJsonResponse` option controls how responses are delivered:

- **`false` (default)**: Uses Server-Sent Events (SSE) streams for responses. Best for tools that may take time to process.
- **`true`**: Returns immediate JSON responses without opening SSE streams. Use this when your tools execute quickly and don't need streaming.

```php
// For fast-executing tools, enable JSON mode
$transport = new StreamableHttpServerTransport(
    host: '127.0.0.1',
    port: 8080,
    enableJsonResponse: true  // Immediate JSON responses
);
```

**Stateless Mode:**

For clients that have issues with session management, enable stateless mode:

```php
$transport = new StreamableHttpServerTransport(
    host: '127.0.0.1',
    port: 8080,
    stateless: true  // Each request is independent
);
```

In stateless mode, session IDs are generated internally but not exposed to clients, and each request is treated as independent without persistent session state.

**Features:**
- **Resumable connections** - clients can reconnect and replay missed events
- **Event sourcing** - all events are stored for replay
- **JSON mode** - optional JSON-only responses for fast tools
- **Enhanced session management** - persistent session state
- **Multiple client support** - designed for concurrent clients
- **Stateless mode** - session-less operation for simple clients

## 📋 Schema Generation and Validation

The server automatically generates JSON schemas for tool parameters using a sophisticated priority system that combines PHP type hints, docblock information, and the optional `#[Schema]` attribute. These generated schemas are used both for input validation and for providing schema information to MCP clients.

### Schema Generation Priority

The server follows this order of precedence when generating schemas:

1. **`#[Schema]` attribute with `definition`** - Complete schema override (highest precedence)
2. **Parameter-level `#[Schema]` attribute** - Parameter-specific schema enhancements
3. **Method-level `#[Schema]` attribute** - Method-wide schema configuration
4. **PHP type hints + docblocks** - Automatic inference from code (lowest precedence)

When a `definition` is provided in the Schema attribute, all automatic inference is bypassed and the complete definition is used as-is.

### Parameter-Level Schema Attributes

```php
use PhpMcp\Server\Attributes\{McpTool, Schema};

#[McpTool(name: 'validate_user')]
public function validateUser(
    #[Schema(format: 'email')]              // PHP already knows it's string
    string $email,
    
    #[Schema(
        pattern: '^[A-Z][a-z]+$',
        description: 'Capitalized name'
    )]
    string $name,
    
    #[Schema(minimum: 18, maximum: 120)]    // PHP already knows it's integer
    int $age
): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
```

### Method-Level Schema

```php
/**
 * Process user data with nested validation.
 */
#[McpTool(name: 'create_user')]
#[Schema(
    properties: [
        'profile' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 2],
                'age' => ['type' => 'integer', 'minimum' => 18],
                'email' => ['type' => 'string', 'format' => 'email']
            ],
            'required' => ['name', 'email']
        ]
    ],
    required: ['profile']
)]
public function createUser(array $userData): array
{
    // PHP type hint provides base 'array' type
    // Method-level Schema adds object structure validation
    return ['id' => 123, 'status' => 'created'];
}
```

### Complete Schema Override (Method-Level Only)

```php
#[McpTool(name: 'process_api_request')]
#[Schema(definition: [
    'type' => 'object',
    'properties' => [
        'endpoint' => ['type' => 'string', 'format' => 'uri'],
        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'DELETE']],
        'headers' => [
            'type' => 'object',
            'patternProperties' => [
                '^[A-Za-z0-9-]+$' => ['type' => 'string']
            ]
        ]
    ],
    'required' => ['endpoint', 'method']
])]
public function processApiRequest(string $endpoint, string $method, array $headers): array
{
    // PHP type hints are completely ignored when definition is provided
    // The schema definition above takes full precedence
    return ['status' => 'processed', 'endpoint' => $endpoint];
}
```

> ⚠️ **Important**: Complete schema definition override should rarely be used. It bypasses all automatic schema inference and requires you to define the entire JSON schema manually. Only use this if you're well-versed with JSON Schema specification and have complex validation requirements that cannot be achieved through the priority system. In most cases, parameter-level and method-level `#[Schema]` attributes provide sufficient flexibility.

## 🎨 Return Value Formatting

The server automatically formats return values from your handlers into appropriate MCP content types:

### Automatic Formatting

```php
// Simple values are auto-wrapped in TextContent
public function getString(): string { return "Hello World"; }           // → TextContent
public function getNumber(): int { return 42; }                        // → TextContent  
public function getBool(): bool { return true; }                       // → TextContent
public function getArray(): array { return ['key' => 'value']; }       // → TextContent (JSON)

// Null handling
public function getNull(): ?string { return null; }                    // → TextContent("(null)")
public function returnVoid(): void { /* no return */ }                 // → Empty content
```

### Advanced Content Types

```php
use PhpMcp\Schema\Content\{TextContent, ImageContent, AudioContent, ResourceContent};

public function getFormattedCode(): TextContent
{
    return TextContent::code('<?php echo "Hello";', 'php');
}

public function getMarkdown(): TextContent  
{
    return TextContent::make('# Title\n\nContent here');
}

public function getImage(): ImageContent
{
    return ImageContent::make(
        data: base64_encode(file_get_contents('image.png')),
        mimeType: 'image/png'
    );
}

public function getAudio(): AudioContent
{
    return AudioContent::make(
        data: base64_encode(file_get_contents('audio.mp3')),
        mimeType: 'audio/mpeg'
    );
}
```

### File and Stream Handling

```php
// File objects are automatically read and formatted
public function getFileContent(): \SplFileInfo
{
    return new \SplFileInfo('/path/to/file.txt');  // Auto-detects MIME type
}

// Stream resources are read completely
public function getStreamContent()
{
    $stream = fopen('/path/to/data.json', 'r');
    return $stream;  // Will be read and closed automatically
}

// Structured resource responses
public function getStructuredResource(): array
{
    return [
        'text' => 'File content here',
        'mimeType' => 'text/plain'
    ];
    
    // Or for binary data:
    // return [
    //     'blob' => base64_encode($binaryData),
    //     'mimeType' => 'application/octet-stream'
    // ];
}
```

## 🔄 Batch Processing

The server automatically handles JSON-RPC batch requests:

```php
// Client can send multiple requests in a single HTTP call:
[
    {"jsonrpc": "2.0", "id": "1", "method": "tools/call", "params": {...}},
    {"jsonrpc": "2.0", "method": "notifications/ping"},              // notification
    {"jsonrpc": "2.0", "id": "2", "method": "tools/call", "params": {...}}
]

// Server returns batch response (excluding notifications):
[
    {"jsonrpc": "2.0", "id": "1", "result": {...}},
    {"jsonrpc": "2.0", "id": "2", "result": {...}}
]
```

## 🔧 Advanced Features

### Completion Providers

Completion providers enable MCP clients to offer auto-completion suggestions in their user interfaces. They are specifically designed for **Resource Templates** and **Prompts** to help users discover available options for dynamic parts like template variables or prompt arguments.

> **Note**: Tools and resources can be discovered via standard MCP commands (`tools/list`, `resources/list`), so completion providers are not needed for them. Completion providers are used only for resource templates (URI variables) and prompt arguments.

The `#[CompletionProvider]` attribute supports three types of completion sources:

#### 1. Custom Provider Classes

For complex completion logic, implement the `CompletionProviderInterface`:

```php
use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Attributes\{McpResourceTemplate, CompletionProvider};

class UserIdCompletionProvider implements CompletionProviderInterface
{
    public function __construct(private DatabaseService $db) {}

    public function getCompletions(string $currentValue, SessionInterface $session): array
    {
        // Dynamic completion from database
        return $this->db->searchUsers($currentValue);
    }
}

class UserService
{
    #[McpResourceTemplate(uriTemplate: 'user://{userId}/profile')]
    public function getUserProfile(
        #[CompletionProvider(provider: UserIdCompletionProvider::class)]  // Class string - resolved from container
        string $userId
    ): array {
        return ['id' => $userId, 'name' => 'John Doe'];
    }
}
```

You can also pass pre-configured provider instances:

```php
class DocumentService  
{
    #[McpPrompt(name: 'document_prompt')]
    public function generatePrompt(
        #[CompletionProvider(provider: new UserIdCompletionProvider($database))]  // Pre-configured instance
        string $userId,
        
        #[CompletionProvider(provider: $this->categoryProvider)]  // Instance from property
        string $category
    ): array {
        return [['role' => 'user', 'content' => "Generate document for user {$userId} in {$category}"]];
    }
}
```

#### 2. Simple List Completions

For static completion lists, use the `values` parameter:

```php
use PhpMcp\Server\Attributes\{McpPrompt, CompletionProvider};

class ContentService
{
    #[McpPrompt(name: 'content_generator')]
    public function generateContent(
        #[CompletionProvider(values: ['blog', 'article', 'tutorial', 'guide', 'documentation'])]
        string $contentType,
        
        #[CompletionProvider(values: ['beginner', 'intermediate', 'advanced', 'expert'])]
        string $difficulty
    ): array {
        return [['role' => 'user', 'content' => "Create a {$difficulty} level {$contentType}"]];
    }
}
```

#### 3. Enum-Based Completions

For enum classes, use the `enum` parameter:

```php
enum Priority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}

enum Status  // Unit enum (no backing values)
{
    case DRAFT;
    case PUBLISHED;
    case ARCHIVED;
}

class TaskService
{
    #[McpTool(name: 'create_task')]
    public function createTask(
        string $title,
        
        #[CompletionProvider(enum: Priority::class)]  // String-backed enum uses values
        string $priority,
        
        #[CompletionProvider(enum: Status::class)]    // Unit enum uses case names
        string $status
    ): array {
        return ['id' => 123, 'title' => $title, 'priority' => $priority, 'status' => $status];
    }
}
```

#### Manual Registration with Completion Providers

```php
$server = Server::make()
    ->withServerInfo('Completion Demo', '1.0.0')
    
    // Using provider class (resolved from container)
    ->withPrompt(
        [DocumentHandler::class, 'generateReport'],
        name: 'document_report'
        // Completion providers are auto-discovered from method attributes
    )
    
    // Using closure with inline completion providers
    ->withPrompt(
        function(
            #[CompletionProvider(values: ['json', 'xml', 'csv', 'yaml'])]
            string $format,
            
            #[CompletionProvider(enum: Priority::class)]
            string $priority
        ): array {
            return [['role' => 'user', 'content' => "Export data in {$format} format with {$priority} priority"]];
        },
        name: 'export_data'
    )
    
    ->build();
```

#### Completion Provider Resolution

The server automatically handles provider resolution:

- **Class strings** (`MyProvider::class`) → Resolved from PSR-11 container with dependency injection
- **Instances** (`new MyProvider()`) → Used directly as-is
- **Values arrays** (`['a', 'b', 'c']`) → Automatically wrapped in `ListCompletionProvider`
- **Enum classes** (`MyEnum::class`) → Automatically wrapped in `EnumCompletionProvider`

> **Important**: Completion providers only offer suggestions to users in the MCP client interface. Users can still input any value, so always validate parameters in your handlers regardless of completion provider constraints.

### Custom Dependency Injection

Your MCP element handlers can use constructor dependency injection to access services like databases, APIs, or other business logic. When handlers have constructor dependencies, you must provide a pre-configured PSR-11 container that contains those dependencies.

By default, the server uses a `BasicContainer` - a simple implementation that attempts to auto-wire dependencies by instantiating classes with parameterless constructors. For dependencies that require configuration (like database connections), you can either manually add them to the BasicContainer or use a more advanced PSR-11 container like PHP-DI or Laravel's container.

```php
use Psr\Container\ContainerInterface;

class DatabaseService
{
    public function __construct(private \PDO $pdo) {}
    
    #[McpTool(name: 'query_users')]
    public function queryUsers(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users');
        return $stmt->fetchAll();
    }
}

// Option 1: Use the basic container and manually add dependencies
$basicContainer = new \PhpMcp\Server\Defaults\BasicContainer();
$basicContainer->set(\PDO::class, new \PDO('sqlite::memory:'));

// Option 2: Use any PSR-11 compatible container (PHP-DI, Laravel, etc.)
$container = new \DI\Container();
$container->set(\PDO::class, new \PDO('mysql:host=localhost;dbname=app', $user, $pass));

$server = Server::make()
    ->withContainer($basicContainer)  // Handlers get dependencies auto-injected
    ->build();
```

### Resource Subscriptions

```php
use PhpMcp\Schema\ServerCapabilities;

$server = Server::make()
    ->withCapabilities(ServerCapabilities::make(
        resourcesSubscribe: true,  // Enable resource subscriptions
        prompts: true,
        tools: true
    ))
    ->build();

// In your resource handler, you can notify clients of changes:
#[McpResource(uri: 'file://config.json')]
public function getConfig(): array
{
    // When config changes, notify subscribers
    $this->notifyResourceChange('file://config.json');
    return ['setting' => 'value'];
}
```

### Resumability and Event Store

For production deployments using `StreamableHttpServerTransport`, you can implement resumability with event sourcing by providing a custom event store:

```php
use PhpMcp\Server\Contracts\EventStoreInterface;
use PhpMcp\Server\Defaults\InMemoryEventStore;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;

// Use the built-in in-memory event store (for development/testing)
$eventStore = new InMemoryEventStore();

// Or implement your own persistent event store
class DatabaseEventStore implements EventStoreInterface
{
    public function storeEvent(string $streamId, string $message): string
    {
        // Store event in database and return unique event ID
        return $this->database->insert('events', [
            'stream_id' => $streamId,
            'message' => $message,
            'created_at' => now()
        ]);
    }

    public function replayEventsAfter(string $lastEventId, callable $sendCallback): void
    {
        // Replay events for resumability
        $events = $this->database->getEventsAfter($lastEventId);
        foreach ($events as $event) {
            $sendCallback($event['id'], $event['message']);
        }
    }
}

// Configure transport with event store
$transport = new StreamableHttpServerTransport(
    host: '127.0.0.1',
    port: 8080,
    eventStore: new DatabaseEventStore()  // Enable resumability
);
```

### Custom Session Handlers

Implement custom session storage by creating a class that implements `SessionHandlerInterface`:

```php
use PhpMcp\Server\Contracts\SessionHandlerInterface;

class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(private \PDO $db) {}

    public function read(string $id): string|false
    {
        $stmt = $this->db->prepare('SELECT data FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $session ? $session['data'] : false;
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO sessions (id, data, updated_at) VALUES (?, ?, ?)'
        );
        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function gc(int $maxLifetime): array
    {
        $cutoff = time() - $maxLifetime;
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE updated_at < ?');
        $stmt->execute([$cutoff]);
        return []; // Return array of cleaned session IDs if needed
    }
}

// Use custom session handler
$server = Server::make()
    ->withSessionHandler(new DatabaseSessionHandler(), 3600)
    ->build();
```

### SSL Context Configuration

For HTTPS deployments of `StreamableHttpServerTransport`, configure SSL context options:

```php
$sslContext = [
    'ssl' => [
        'local_cert' => '/path/to/certificate.pem',
        'local_pk' => '/path/to/private-key.pem',
        'verify_peer' => false,
        'allow_self_signed' => true,
    ]
];

$transport = new StreamableHttpServerTransport(
    host: '0.0.0.0',
    port: 8443,
    sslContext: $sslContext
);
```

> **SSL Context Reference**: For complete SSL context options, see the [PHP SSL Context Options documentation](https://www.php.net/manual/en/context.ssl.php).
## 🔍 Error Handling & Debugging

The server provides comprehensive error handling and debugging capabilities:

### Exception Handling

Tool handlers can throw any PHP exception when errors occur. The server automatically converts these exceptions into proper JSON-RPC error responses for MCP clients.

```php
#[McpTool(name: 'divide_numbers')]
public function divideNumbers(float $dividend, float $divisor): float
{
    if ($divisor === 0.0) {
        // Any exception with descriptive message will be sent to client
        throw new \InvalidArgumentException('Division by zero is not allowed');
    }
    
    return $dividend / $divisor;
}

#[McpTool(name: 'calculate_factorial')]
public function calculateFactorial(int $number): int
{
    if ($number < 0) {
        throw new \InvalidArgumentException('Factorial is not defined for negative numbers');
    }
    
    if ($number > 20) {
        throw new \OverflowException('Number too large, factorial would cause overflow');
    }
    
    // Implementation continues...
    return $this->factorial($number);
}
```

The server will convert these exceptions into appropriate JSON-RPC error responses that MCP clients can understand and display to users.

### Logging and Debugging

```php
use Psr\Log\LoggerInterface;

class DebugAwareHandler
{
    public function __construct(private LoggerInterface $logger) {}
    
    #[McpTool(name: 'debug_tool')]
    public function debugTool(string $data): array
    {
        $this->logger->info('Processing debug tool', ['input' => $data]);
        
        // For stdio transport, use STDERR for debug output
        fwrite(STDERR, "Debug: Processing data length: " . strlen($data) . "\n");
        
        return ['processed' => true];
    }
}
```

## 🚀 Production Deployment

Since `$server->listen()` runs a persistent process, you can deploy it using any strategy that suits your infrastructure needs. The server can be deployed on VPS, cloud instances, containers, or any environment that supports long-running processes.

Here are two popular deployment approaches to consider:

### Option 1: VPS with Supervisor + Nginx (Recommended)

**Best for**: Most production deployments, cost-effective, full control

```bash
# 1. Install your application on VPS
git clone https://github.com/yourorg/your-mcp-server.git /var/www/mcp-server
cd /var/www/mcp-server
composer install --no-dev --optimize-autoloader

# 2. Install Supervisor
sudo apt-get install supervisor

# 3. Create Supervisor configuration
sudo nano /etc/supervisor/conf.d/mcp-server.conf
```

**Supervisor Configuration:**
```ini
[program:mcp-server]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mcp-server/server.php --transport=http --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/mcp-server.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
```

**Nginx Configuration with SSL:**
```nginx
# /etc/nginx/sites-available/mcp-server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name mcp.yourdomain.com;

    # SSL configuration
    ssl_certificate /etc/letsencrypt/live/mcp.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mcp.yourdomain.com/privkey.pem;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # MCP Server proxy
    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        
        # Important for SSE connections
        proxy_buffering off;
        proxy_cache off;
        
        proxy_pass http://127.0.0.1:8080/;
    }
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name mcp.yourdomain.com;
    return 301 https://$server_name$request_uri;
}
```

**Start Services:**
```bash
# Enable and start supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mcp-server:*

# Enable and start nginx
sudo systemctl enable nginx
sudo systemctl restart nginx

# Check status
sudo supervisorctl status
```

**Client Configuration:**
```json
{
  "mcpServers": {
    "my-server": {
      "url": "https://mcp.yourdomain.com/mcp"
    }
  }
}
```

### Option 2: Docker Deployment

**Best for**: Containerized environments, Kubernetes, cloud platforms

**Production Dockerfile:**
```dockerfile
FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk --no-cache add \
    nginx \
    supervisor \
    && docker-php-ext-enable opcache

# Install PHP extensions for MCP
RUN docker-php-ext-install pdo_mysql pdo_sqlite opcache

# Create application directory
WORKDIR /var/www/mcp

# Copy application code
COPY . /var/www/mcp
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/production.ini

# Install Composer dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions
RUN chown -R www-data:www-data /var/www/mcp

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
```

**docker-compose.yml:**
```yaml
services:
  mcp-server:
    build: .
    ports:
      - "8080:80"
    environment:
      - MCP_ENV=production
      - MCP_LOG_LEVEL=info
    volumes:
      - ./storage:/var/www/mcp/storage
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3

  # Optional: Add database if needed
  database:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: secure_password
      MYSQL_DATABASE: mcp_server
    volumes:
      - mysql_data:/var/lib/mysql
    restart: unless-stopped

volumes:
  mysql_data:
```

### Security Best Practices

1. **Firewall Configuration:**
```bash
# Only allow necessary ports
sudo ufw allow ssh
sudo ufw allow 80
sudo ufw allow 443
sudo ufw deny 8080  # MCP port should not be publicly accessible
sudo ufw enable
```

2. **SSL/TLS Setup:**
```bash
# Install Certbot for Let's Encrypt
sudo apt install certbot python3-certbot-nginx

# Generate SSL certificate
sudo certbot --nginx -d mcp.yourdomain.com
```

## 📚 Examples & Use Cases

Explore comprehensive examples in the [`examples/`](./examples/) directory:

### Available Examples

- **`01-discovery-stdio-calculator/`** - Basic stdio calculator with attribute discovery
- **`02-discovery-http-userprofile/`** - HTTP server with user profile management  
- **`03-manual-registration-stdio/`** - Manual element registration patterns
- **`04-combined-registration-http/`** - Combining manual and discovered elements
- **`05-stdio-env-variables/`** - Environment variable handling
- **`06-custom-dependencies-stdio/`** - Dependency injection with task management
- **`07-complex-tool-schema-http/`** - Advanced schema validation examples
- **`08-schema-showcase-streamable/`** - Comprehensive schema feature showcase

### Running Examples

```bash
# Navigate to an example directory
cd examples/01-discovery-stdio-calculator/

# Make the server executable
chmod +x server.php

# Run the server (or configure it in your MCP client)
./server.php
```

## 🚧 Migration from v2.x

If migrating from version 2.x, note these key changes:

### Schema Updates
- Uses `php-mcp/schema` package for DTOs instead of internal classes
- Content types moved to `PhpMcp\Schema\Content\*` namespace
- Updated method signatures for better type safety

### Session Management
- New session management with multiple backends
- Use `->withSession()` or `->withSessionHandler()` for configuration
- Sessions are now persistent across reconnections (with cache backend)

### Transport Changes
- New `StreamableHttpServerTransport` with resumability
- Enhanced error handling and event sourcing
- Better batch request processing

## 🧪 Testing

```bash
# Install development dependencies
composer install --dev

# Run the test suite
composer test

# Run tests with coverage (requires Xdebug)
composer test:coverage

# Run code style checks
composer lint
```

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📄 License

The MIT License (MIT). See [LICENSE](LICENSE) for details.

## 🙏 Acknowledgments

- Built on the [Model Context Protocol](https://modelcontextprotocol.io/) specification
- Powered by [ReactPHP](https://reactphp.org/) for async operations
- Uses [PSR standards](https://www.php-fig.org/) for maximum interoperability
