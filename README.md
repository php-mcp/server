# PHP MCP Server

[![Latest Version on Packagist](https://img.shields.io/packagist/v/php-mcp/server.svg?style=flat-square)](https://packagist.org/packages/php-mcp/server)
[![Total Downloads](https://img.shields.io/packagist/dt/php-mcp/server.svg?style=flat-square)](https://packagist.org/packages/php-mcp/server)
[![Tests](https://img.shields.io/github/actions/workflow/status/php-mcp/server/tests.yml?branch=main&style=flat-square)](https://github.com/php-mcp/server/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/php-mcp/server.svg?style=flat-square)](LICENSE)

**PHP MCP Server provides a robust and flexible server-side implementation of the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/introduction) for PHP applications.**

Easily expose parts of your application as standardized MCP **Tools**, **Resources**, and **Prompts**, allowing AI assistants (like Anthropic's Claude, Cursor IDE, etc.) to interact with your PHP backend using the MCP standard.

This package simplifies building MCP servers through:

*   **Attribute-Based Definition:** Define MCP elements using PHP 8 Attributes (`#[McpTool]`, `#[McpResource]`, `#[McpPrompt]`, `#[McpResourceTemplate]`) on your methods or invokable classes.
*   **Manual Registration:** Programmatically register elements using a fluent builder API.
*   **Explicit Discovery:** Trigger attribute scanning on demand via the `$server->discover()` method.
*   **Metadata Inference:** Intelligently generate MCP schemas and descriptions from PHP type hints and DocBlocks.
*   **Selective Caching:** Optionally cache *discovered* element definitions to speed up startup, while always preserving manually registered elements.
*   **Flexible Transports:** Supports `stdio` and `http+sse`, separating core logic from network communication.
*   **PSR Compliance:** Integrates with PSR-3 (Logging), PSR-11 (Container), and PSR-16 (SimpleCache).

This package currently supports the `2024-11-05` version of the Model Context Protocol.

## Requirements

*   PHP >= 8.1
*   Composer
*   *(For Http Transport)*: An event-driven PHP environment capable of handling concurrent requests (see [HTTP Transport](#http-transport-httpsse) section).

## Installation

```bash
composer require php-mcp/server
```

> **Note for Laravel Users:** While this package works standalone, consider using [`php-mcp/laravel`](https://github.com/php-mcp/laravel) for enhanced framework integration, configuration, and Artisan commands.

## Quick Start: Standalone `stdio` Server with Discovery

This example creates a server using **attribute discovery** to find elements and runs via the `stdio` transport.

**1. Define Your MCP Element:**

Create `src/MyMcpElements.php`:

```php
<?php
namespace App;

use PhpMcp\Server\Attributes\McpTool;

class MyMcpElements
{
    /**
     * Adds two numbers together.
     * @param int $a The first number.
     * @param int $b The second number.
     * @return int The sum.
     */
    #[McpTool(name: 'simple_adder')]
    public function add(int $a, int $b): int
    {
        fwrite(STDERR, "Executing simple_adder with a=$a, b=$b\n");
        return $a + $b;
    }
}
```

**2. Create the Server Script:**

Create `mcp-server.php`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

try {
    // 1. Build the Server configuration
    $server = Server::make()
        ->withServerInfo('My Discovery Server', '1.0.2')
        ->build();

    // 2. **Explicitly run discovery**
    $server->discover(
        basePath: __DIR__,
        scanDirs: ['src'],
    );

    // 3. Create the Stdio Transport
    $transport = new StdioServerTransport();

    // 4. Start Listening (BLOCKING call)
    $server->listen($transport);

    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[MCP SERVER CRITICAL ERROR]\n" . $e . "\n");
    exit(1);
}
```

**3. Configure Your MCP Client:**

Instruct your MCP client (e.g., Cursor, Claude Desktop) to use the `stdio` transport by running your script. Make sure to use the **absolute path**:

```json
// Example: .cursor/mcp.json
{
    "mcpServers": {
        "my-php-stdio": {
            "command": "php",
            "args": ["/full/path/to/your/project/mcp-server.php"]
        }
    }
}
```

**Flow:**

1.  `Server::make()->...->build()`: Creates the `Server` instance, resolves dependencies, performs *manual* registrations (if any), and implicitly attempts to load *discovered* elements from cache (if configured and cache exists).
2.  `$server->discover(__DIR__, ['src'])`: Explicitly triggers a filesystem scan within `src/`. Clears previously discovered/cached elements from the registry, finds `MyMcpElements::add`, creates its `ToolDefinition`, and registers it. If caching is enabled and `saveToCache` is true, saves this discovered definition to the cache.
3.  `$server->listen($transport)`: Binds the transport, checks if *any* elements are registered (in this case, yes), starts the transport listener, and runs the event loop.

## Core Architecture

The server uses a decoupled architecture:

*   **`ServerBuilder`:** Fluent interface (`Server::make()->...`) for configuration. Collects server identity, dependencies (Logger, Cache, Container, Loop), capabilities, and **manual** element registrations. Calls `build()` to create the `Server` instance.
*   **`Configuration`:** A value object holding the resolved configuration and dependencies.
*   **`Server`:** The central object holding the configured state and core logic components (`Registry`, `Protocol`, `Configuration`). It's transport-agnostic. Provides methods to `discover()` elements and `listen()` via a specific transport.
*   **`Protocol`:** Internal bridge listening to transport events and processes JSON-RPC messages from the transport.
*   **`Registry`:** Stores MCP element definitions. **Distinguishes between manually registered and discovered elements.** Handles optional caching of *discovered* elements only. Loads cached discovered elements upon instantiation if available.
*   **`ServerTransportInterface`:** Event-driven interface for server-side transports (`StdioServerTransport`, `HttpServerTransport`). Handles communication, emits events.

## Defining MCP Elements

Expose your application's functionality as MCP Tools, Resources, or Prompts using attributes or manual registration.

### 1. Using Attributes (`#[Mcp*]`)

Decorate public, non-static methods or invokable classes with  `#[Mcp*]` attributes to mark them as MCP Elements. After building the server, you **must** call `$server->discover(...)` at least once with the correct paths to find and register these elements. It will also cache the discovered elements if set, so that you can skip discovery on subsequent runs.

```php
$server = ServerBuilder::make()->...->build();
// Scan 'src/Handlers' relative to the project root
$server->discover(basePath: __DIR__, scanDirs: ['src/Handlers']);
```

Attributes:

*   **`#[McpTool(name?, description?, annotations?)`**: Defines an action. Parameters/return types/DocBlocks define the MCP schema. Use on public, non-static methods or invokable classes.
*   **`#[McpResource(uri, name?, description?, mimeType?, size?, annotations?)]`**: Defines a static resource instance. Use on public, non-static methods or invokable classes. Method returns resource content.
*   **`#[McpResourceTemplate(uriTemplate, name?, description?, mimeType?, annotations?)]`**: Defines a handler for templated URIs (e.g., `item://{id}`). Use on public, non-static methods or invokable classes. Method parameters must match template variables. Method returns content for the resolved instance.
*   **`#[McpPrompt(name?, description?)`**: Defines a prompt generator. Use on public, non-static methods or invokable classes. Method parameters are prompt arguments. Method returns prompt messages.

*(See [Attribute Details](#attribute-details-and-return-formatting) below for more on parameters and return value formatting)*

### 2. Manual Registration (`ServerBuilder->with*`)

Use `withTool`, `withResource`, `withResourceTemplate`, `withPrompt` on the `ServerBuilder` *before* calling `build()`.

```php
use App\Handlers\MyToolHandler;
use App\Handlers\MyResourceHandler;

$server = Server::make()
    ->withServerInfo(...)
    ->withTool(
        [MyToolHandler::class, 'processData'], // Handler: [class, method]
        'data_processor'                       // MCP Name (Optional)
    )
    ->withResource(
        MyResourceHandler::class,              // Handler: Invokable class
        'config://app/name'                    // URI (Required)
    )
    // ->withResourceTemplate(...)
    // ->withPrompt(...)
    ->build();
```

*   **Handlers:** Can be `[ClassName::class, 'methodName']` or `InvokableHandler::class`. Dependencies are resolved via the configured PSR-11 Container.
*   Metadata (name, description) is inferred from the handler if not provided explicitly.
*   These elements are registered **immediately** when `build()` is called.
*   They are **never cached** by the Registry's caching mechanism.
*   They are **not removed** when `$registry->clearDiscoveredElements()` is called (e.g., at the start of `$server->discover()`).

### Precedence: Manual vs. Discovered/Cached

If an element is registered both manually (via the builder) and is also found via attribute discovery (or loaded from cache) with the **same identifying key** (tool name, resource URI, prompt name, template URI):

*   **The manually registered element always takes precedence.**
*   The discovered/cached version will be ignored, and a debug message will be logged.

This ensures explicit manual configuration overrides any potentially outdated discovered or cached definitions.

## Discovery and Caching

Attribute discovery is an **explicit step** performed on a built `Server` instance.

*   **`$server->discover(string $basePath, array $scanDirs = [...], array $excludeDirs = [...], bool $force = false, bool $saveToCache = true)`**
    *   `$basePath`, `$scanDirs`, `$excludeDirs`: Define where to scan.
    *   `$force`: If `true`, forces a re-scan even if discovery ran earlier in the same script execution. Default is `false`.
    *   `$saveToCache`: If `true` (default) and a PSR-16 cache was provided to the builder, the results of *this scan* (discovered elements only) will be saved to the cache, overwriting previous cache content. If `false` or no cache is configured, results are not saved.
*   **Default Behavior:** Calling `discover()` performs a fresh scan. It first clears previously discovered items from the cache `$saveToCache` is true), then scans the filesystem, registers found elements (marking them as discovered), and finally saves the newly discovered elements to cache if `$saveToCache` is true.
*   **Implicit Cache Loading:** When `ServerBuilder::build()` creates the `Registry`, the `Registry` constructor automatically attempts to load *discovered* elements from the cache (if a cache was configured and the cache key exists). Manually registered elements are added *after* this potential cache load.
*   **Cache Content:** Only elements found via discovery are stored in the cache. Manually registered elements are never cached.

## Configuration (`ServerBuilder`)

You can get a server builder instance by either calling `new ServerBuilder` or more conveniently using `Server::make()`. The available methods for configuring your server instance include:

*   **`withServerInfo(string $name, string $version)`**: **Required.** Server identity.
*   **`withLogger(LoggerInterface $logger)`**: Optional. PSR-3 logger. Defaults to `NullLogger`.
*   **`withCache(CacheInterface $cache, int $ttl = 3600)`**: Optional. PSR-16 cache for registry and client state. Defaults to `ArrayCache` only for the client state manager. 
*   **`withContainer(ContainerInterface $container)`**: Optional. PSR-11 container for resolving *your handler classes*. Defaults to `BasicContainer`.
*   **`withLoop(LoopInterface $loop)`**: Optional. ReactPHP event loop. Defaults to `Loop::get()`.
*   **`withCapabilities(Capabilities $capabilities)`**: Optional. Configure advertised capabilities (e.g., resource subscriptions). Use `Capabilities::forServer(...)`.
*   **`withPaginationLimit(int $paginationLimit)`: Optional. Configures the server's pagination limit for list requests.
*   `withTool(...)`, `withResource(...)`, etc.: Optional manual registration.

## Running the Server (Transports)

The core `Server` object doesn't handle network I/O directly. You activate it using a specific transport implementation passed to `$server->listen($transport)`.

### Stdio Transport

Handles communication over Standard Input/Output. Ideal for servers launched directly by an MCP client (like Cursor).

```php
use PhpMcp\Server\Transports\StdioServerTransport;

// ... build $server ...

$transport = new StdioServerTransport();

// This blocks until the transport is closed (e.g., SIGINT/SIGTERM)
$server->listen($transport);
```

> **Warning:** When using `StdioServerTransport`, your application code (including tool/resource handlers) **MUST NOT** write arbitrary output to `STDOUT` (using `echo`, `print`, `var_dump`, etc.). `STDOUT` is reserved for sending framed JSON-RPC messages back to the client. Use `STDERR` for logging or debugging output:
> ```php
> fwrite(STDERR, "Debug: Processing tool X\n");
> // Or use a PSR-3 logger configured to write to STDERR:
> // $logger->debug("Processing tool X", ['param' => $value]);
> ```

### HTTP Transport (HTTP+SSE)

Listens for HTTP connections, handling client messages via POST and sending server messages/notifications via Server-Sent Events (SSE).

```php
use PhpMcp\Server\Transports\HttpServerTransport;

// ... build $server ...

$transport = new HttpServerTransport(
    host: '127.0.0.1',   // Listen on all interfaces
    port: 8080,          // Port to listen on
    mcpPathPrefix: 'mcp' // Base path for endpoints (/mcp/sse, /mcp/message)
    // sslContext: [...] // Optional: ReactPHP socket context for HTTPS
);

// This blocks, starting the HTTP server and running the event loop
$server->listen($transport);
```

**Concurrency Requirement:** The `HttpServerTransport` relies on ReactPHP's non-blocking I/O model. It's designed to handle multiple concurrent SSE connections efficiently. Running this transport requires a PHP environment that supports an event loop and non-blocking operations. **It will generally NOT work correctly with traditional synchronous web servers like Apache+mod_php or the built-in PHP development server.** You should run the `listen()` command using the PHP CLI in a persistent process (potentially managed by Supervisor, Docker, etc.).

**Endpoints:**
*   **SSE:** `GET /{mcpPathPrefix}/sse` (e.g., `GET /mcp/sse`) - Client connects here.
*   **Messages:** `POST /{mcpPathPrefix}/message?clientId={clientId}` (e.g., `POST /mcp/message?clientId=sse_abc123`) - Client sends requests here. The `clientId` query parameter is essential for the server to route the message correctly to the state associated with the SSE connection. The server sends the POST path (including the generated `clientId`) via the initial `endpoint` SSE event to the client, so you will never have to manually handle this.

## Connecting MCP Clients

Instruct clients how to connect to your server:

*   **`stdio`:** Provide the full command to execute your server script (e.g., `php /path/to/mcp-server.php`). The client needs execute permissions.
*   **`http`:** Provide the full URL to your SSE endpoint (e.g., `http://your.domain:8080/mcp/sse`). Ensure the server process running `listen()` is accessible.

Refer to specific client documentation (Cursor, Claude Desktop, etc.) for their configuration format.

## Attribute Details & Return Formatting {#attribute-details-and-return-formatting}

These attributes mark classes or methods to be found by the `->discover()` process.

#### `#[McpTool]`

Marks a method **or an invokable class** as an MCP Tool. Tools represent actions or functions the client can invoke, often with parameters.

**Usage:**

*   **On a Method:** Place the attribute directly above a public, non-static method.
*   **On an Invokable Class:** Place the attribute directly above a class definition that contains a public `__invoke` method. The `__invoke` method will be treated as the tool's handler.

The attribute accepts the following parameters:

*   `name` (optional): The name of the tool exposed to the client.
    *   When on a method, defaults to the method name (e.g., `addNumbers` becomes `addNumbers`).
    *   When on an invokable class, defaults to the class's short name (e.g., `class AdderTool` becomes `AdderTool`).
*   `description` (optional): A description for the tool. Defaults to the method's DocBlock summary (or the `__invoke` method's summary if on a class).

The parameters (including name, type hints, and defaults) of the target method (or `__invoke`) define the tool's input schema. The return type hint defines the output schema. DocBlock `@param` and `@return` descriptions are used for parameter/output descriptions.

**Return Value Formatting**

The value returned by your method determines the content sent back to the client. The library automatically formats common types:

*   `null`: Returns empty content (if return type hint is `void`) or `TextContent` with `(null)`.
*   `string`, `int`, `float`, `bool`: Automatically wrapped in `PhpMcp\Server\JsonRpc\Contents\TextContent`.
*   `array`, `object`: Automatically JSON-encoded (pretty-printed) and wrapped in `TextContent`.
*   `PhpMcp\Server\JsonRpc\Contents\Content` object(s): If you return an instance of `Content` (e.g., `TextContent`, `ImageContent`, `AudioContent`, `ResourceContent`) or an array of `Content` objects, they are used directly. This gives you full control over the output format. *Example:* `return TextContent::code('echo \'Hello\';', 'php');`
*   Exceptions: If your method throws an exception, a `TextContent` containing the error message and type is returned.

The method's return type hint (`@return` tag in DocBlock) is used to generate the tool's output schema, but the actual formatting depends on the *value* returned at runtime.

**Schema Generation**

The server automatically generates JSON Schema for tool parameters based on:

1. PHP type hints
2. DocBlock annotations
3. Schema attributes (for enhanced validation)

**Examples:**

```php
/**
 * Fetches user details by ID.
 *
 * @param int $userId The ID of the user to fetch.
 * @param bool $includeEmail Include the email address?
 * @return array{id: int, name: string, email?: string} User details.
 */
#[McpTool(name: 'get_user')]
public function getUserById(int $userId, bool $includeEmail = false): array
{
    // ... implementation returning an array ...
}

/**
 * Process user data with nested structures.
 * 
 * @param array{name: string, contact: array{email: string, phone?: string}} $userData
 * @param string[] $tags Tags associated with the user
 * @return array{success: bool, message: string}
 */
#[McpTool]
public function processUserData(array $userData, array $tags): array {
    // Implementation
}

/**
 * Returns PHP code as formatted text.
 *
 * @return TextContent
 */
#[McpTool(name: 'get_php_code')]
public function getPhpCode(): TextContent
{
    return TextContent::code('<?php echo \'Hello World\';', 'php');
}

/**
 * An invokable class acting as a tool.
 */
#[McpTool(description: 'An invokable adder tool.')]
class AdderTool {
    /**
     * Adds two numbers.
     * @param int $a First number.
     * @param int $b Second number.
     * @return int The sum.
     */
    public function __invoke(int $a, int $b): int {
        return $a + $b;
    }
}
```

**Additional Validation with `#[Schema]`**

For enhanced schema generation and parameter validation, you can use the `Schema` attribute:

```php
use PhpMcp\Server\Attributes\Schema;
use PhpMcp\Server\Attributes\Schema\Format;
use PhpMcp\Server\Attributes\Schema\ArrayItems;
use PhpMcp\Server\Attributes\Schema\Property;

/**
 * Validates user information.
 */
#[McpTool]
public function validateUser(
    #[Schema(format: 'email')] 
    string $email,
    
    #[Schema(minItems: 2, uniqueItems: true)]
    array $tags
): bool {
    // Implementation
}
```

The Schema attribute adds JSON Schema constraints like string formats, numeric ranges, array constraints, and object property validations.

#### `#[McpResource]`

Marks a method **or an invokable class** as representing a specific, static MCP Resource instance. Resources represent pieces of content or data identified by a URI. The target method (or `__invoke`) will typically be called when a client performs a `resources/read` for the specified URI.

**Usage:**

*   **On a Method:** Place the attribute directly above a public, non-static method.
*   **On an Invokable Class:** Place the attribute directly above a class definition that contains a public `__invoke` method. The `__invoke` method will be treated as the resource handler.

The attribute accepts the following parameters:

*   `uri` (required): The unique URI for this resource instance (e.g., `config://app/settings`, `file:///data/status.txt`). Must conform to [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986).
*   `name` (optional): Human-readable name. Defaults inferred from method name or class short name.
*   `description` (optional): Description. Defaults to DocBlock summary of the method or `__invoke`.
*   `mimeType` (optional): The resource's MIME type (e.g., `text/plain`, `application/json`).
*   `size` (optional): Resource size in bytes, if known and static.
*   `annotations` (optional): Array of MCP annotations (e.g., `['audience' => ['user']]`).

The target method (or `__invoke`) should return the content of the resource.

**Return Value Formatting**

The return value determines the resource content:

*   `string`: Treated as text content. MIME type is taken from the attribute or guessed (`text/plain`, `application/json`, `text/html`).
*   `array`: If the attribute's `mimeType` is `application/json` (or contains `json`), the array is JSON-encoded. Otherwise, it attempts JSON encoding with a warning.
*   `stream resource`: Content is read from the stream. `mimeType` must be provided in the attribute or defaults to `application/octet-stream`.
*   `SplFileInfo` object: Content is read from the file. `mimeType` is taken from the attribute or guessed.
*   `PhpMcp\Server\JsonRpc\Contents\EmbeddedResource`: Used directly. Gives full control over URI, MIME type, text/blob content.
*   `PhpMcp\Server\JsonRpc\Contents\ResourceContent`: The inner `EmbeddedResource` is extracted and used.
*   `array{'blob': string, 'mimeType'?: string}`: Creates a blob resource.
*   `array{'text': string, 'mimeType'?: string}`: Creates a text resource.

```php
#[McpResource(uri: 'status://system/load', mimeType: 'text/plain')]
public function getSystemLoad(): string
{
    return file_get_contents('/proc/loadavg');
}

/**
 * An invokable class providing system load resource.
 */
#[McpResource(uri: 'status://system/load/invokable', mimeType: 'text/plain')]
class SystemLoadResource {
    public function __invoke(): string {
        return file_get_contents('/proc/loadavg');
    }
}
```

#### `#[McpResourceTemplate]`

Marks a method **or an invokable class** that can generate resource instances based on a template URI. This is useful for resources whose URI contains variable parts (like user IDs or document IDs). The target method (or `__invoke`) will be called when a client performs a `resources/read` matching the template.

**Usage:**

*   **On a Method:** Place the attribute directly above a public, non-static method.
*   **On an Invokable Class:** Place the attribute directly above a class definition that contains a public `__invoke` method.

The attribute accepts the following parameters:

*   `uriTemplate` (required): The URI template string, conforming to [RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570) (e.g., `user://{userId}/profile`, `document://{docId}?format={fmt}`).
*   `name`, `description`, `mimeType`, `annotations` (optional): Similar to `#[McpResource]`, but describe the template itself. Defaults inferred from method/class name and DocBlocks.

The parameters of the target method (or `__invoke`) *must* match the variables defined in the `uriTemplate`. The method should return the content for the resolved resource instance.

**Return Value Formatting**

Same as `#[McpResource]` (see above). The returned value represents the content of the *resolved* resource instance.

```php
/**
 * Gets a user's profile data.
 *
 * @param string $userId The user ID from the URI.
 * @return array The user profile.
 */
#[McpResourceTemplate(uriTemplate: 'user://{userId}/profile', name: 'user_profile', mimeType: 'application/json')]
public function getUserProfile(string $userId): array
{
    // Fetch user profile for $userId
    return ['id' => $userId, /* ... */ ];
}

/**
 * An invokable class providing user profiles via template.
 */
#[McpResourceTemplate(uriTemplate: 'user://{userId}/profile/invokable', name: 'user_profile_invokable', mimeType: 'application/json')]
class UserProfileTemplate {
    /**
     * Gets a user's profile data.
     * @param string $userId The user ID from the URI.
     * @return array The user profile.
     */
    public function __invoke(string $userId): array {
        // Fetch user profile for $userId
        return ['id' => $userId, 'source' => 'invokable', /* ... */ ];
    }
}
```

#### `#[McpPrompt]`

Marks a method **or an invokable class** as an MCP Prompt generator. Prompts are pre-defined templates or functions that generate conversational messages (like user or assistant turns) based on input parameters.

**Usage:**

*   **On a Method:** Place the attribute directly above a public, non-static method.
*   **On an Invokable Class:** Place the attribute directly above a class definition that contains a public `__invoke` method.

The attribute accepts the following parameters:

*   `name` (optional): The prompt name. Defaults to method name or class short name.
*   `description` (optional): Description. Defaults to DocBlock summary of the method or `__invoke`.

Method parameters (or `__invoke` parameters) define the prompt's input arguments. The method should return the prompt content, typically an array conforming to the MCP message structure.

**Return Value Formatting**

Your method should return the prompt messages in one of these formats:

*   **Array of `PhpMcp\Server\JsonRpc\Contents\PromptMessage` objects**: The recommended way for full control.
    *   `PromptMessage::user(string|Content $content)`
    *   `PromptMessage::assistant(string|Content $content)`
    *   The `$content` can be a simple string (becomes `TextContent`) or any `Content` object (`TextContent`, `ImageContent`, `ResourceContent`, etc.).
*   **Simple list array:** `[['role' => 'user', 'content' => 'Some text'], ['role' => 'assistant', 'content' => $someContentObject]]`
    *   `role` must be `'user'` or `'assistant'`.
    *   `content` can be a string (becomes `TextContent`) or a `Content` object.
    *   `content` can also be an array structure like `['type' => 'image', 'data' => '...', 'mimeType' => '...']`, `['type' => 'text', 'text' => '...']`, or `['type' => 'resource', 'resource' => ['uri' => '...', 'text|blob' => '...']]`.
*   **Simple associative array:** `['user' => 'User prompt text', 'assistant' => 'Optional assistant prefix']` (converted to one or two `PromptMessage`s with `TextContent`).

```php
/**
 * Generates a prompt to summarize text.
 *
 * @param string $textToSummarize The text to summarize.
 * @return array The prompt messages.
 */
#[McpPrompt(name: 'summarize')]
public function generateSummaryPrompt(string $textToSummarize): array
{
    return [
        ['role' => 'user', 'content' => "Summarize the following text:\n\n{$textToSummarize}"],
    ];
}

/**
 * An invokable class generating a summary prompt.
 */
#[McpPrompt(name: 'summarize_invokable')]
class SummarizePrompt {
     /**
     * Generates a prompt to summarize text.
     * @param string $textToSummarize The text to summarize.
     * @return array The prompt messages.
     */
    public function __invoke(string $textToSummarize): array {
        return [
            ['role' => 'user', 'content' => "[Invokable] Summarize:

{$textToSummarize}"],
        ];
    }
}
```

## Error Handling

The server uses specific exceptions inheriting from `PhpMcp\Server\Exception\McpServerException`. The `Protocol` catches these and `Throwable` during message processing, converting them to appropriate JSON-RPC error responses. Transport-level errors are emitted via the transport's `error` event.

## Examples

See the [`examples/`](./examples/) directory:

*   **`01-discovery-stdio-calculator/`**: Basic `stdio` server demonstrating attribute discovery for a simple calculator.
*   **`02-discovery-http-userprofile/`**: `http+sse` server using discovery for a user profile service.
*   **`03-manual-registration-stdio/`**: `stdio` server showcasing only manual element registration.
*   **`04-combined-registration-http/`**: `http+sse` server combining manual and discovered elements, demonstrating precedence.
*   **`05-stdio-env-variables/`**: `stdio` server with a tool that uses environment variables passed by the MCP client.
*   **`06-custom-dependencies-stdio/`**: `stdio` server showing DI container usage for injecting services into MCP handlers (Task Manager example).
*   **`07-complex-tool-schema-http/`**: `http+sse` server with a tool demonstrating complex input schemas (optionals, defaults, enums).

## Testing

```bash
composer install --dev
composer test
composer test:coverage # Requires Xdebug
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

The MIT License (MIT). See [LICENSE](LICENSE).

