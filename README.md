# PHP MCP Server

Core PHP implementation of the **Model Context Protocol (MCP)** server.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/php-mcp/server.svg?style=flat-square)](https://packagist.org/packages/php-mcp/server)
[![Total Downloads](https://img.shields.io/packagist/dt/php-mcp/server.svg?style=flat-square)](https://packagist.org/packages/php-mcp/server)
[![Tests](https://github.com/php-mcp/server/actions/workflows/tests.yml/badge.svg)](https://github.com/php-mcp/server/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/php-mcp/server.svg?style=flat-square)](LICENSE)

## Introduction

The [Model Context Protocol (MCP)](https://modelcontextprotocol.io/introduction) is an open standard, initially developed by Anthropic, designed to standardize how AI assistants and tools connect to external data sources, APIs, and other systems. Think of it like USB-C for AI – a single, consistent way to provide context.

`php-mcp/server` is a PHP library that makes it incredibly easy to build MCP-compliant servers. Its core goal is to allow you to expose parts of your existing PHP application – specific methods – as MCP **Tools**, **Resources**, or **Prompts** with minimal effort, primarily using PHP 8 Attributes.

This package currently supports the `2024-11-05` version of the Model Context Protocol and is compatible with various MCP clients like Claude Desktop, Cursor, Windsurf, and others that adhere to this protocol version.

## Key Features

*   **Attribute-Based Definition:** Define MCP elements (Tools, Resources, Prompts, Templates) using simple PHP 8 Attributes (`#[McpTool]`, `#[McpResource]`, `#[McpPrompt]`, `#[McpResourceTemplate]`, `#[McpTemplate]`) on your methods or **directly on invokable classes**.
*   **Manual Registration:** Programmatically register MCP elements using fluent methods on the `Server` instance (e.g., `->withTool()`, `->withResource()`).
*   **Automatic Metadata Inference:** Leverages method names, parameter names, PHP type hints (for schema), and DocBlocks (for schema and descriptions) to automatically generate MCP definitions, minimizing boilerplate code.
*   **PSR Compliant:** Integrates seamlessly with standard PHP interfaces:
    *   `PSR-3` (LoggerInterface): Bring your own logger (e.g., Monolog).
    *   `PSR-11` (ContainerInterface): Use your favorite DI container (e.g., Laravel, Symfony, PHP-DI) for resolving your application classes and their dependencies when MCP elements are invoked.
    *   `PSR-16` (SimpleCacheInterface): Provide a cache implementation (e.g., Symfony Cache, Laravel Cache) for discovered elements and transport state.
*   **Flexible Configuration:** Starts with sensible defaults but allows providing your own implementations for logging, caching, DI container, and detailed MCP configuration (`ConfigurationRepositoryInterface`).
*   **Multiple Transports:** Supports `stdio` (for command-line clients) and includes components for building `http+sse` (HTTP + Server-Sent Events) transports out-of-the-box (requires integration with a HTTP server).
*   **Automatic Discovery:** Scans specified directories within your project to find classes and methods annotated with MCP attributes.
*   **Framework Agnostic:** Designed to work equally well in vanilla PHP projects or integrated into any PHP framework.

## Requirements

*   PHP >= 8.1
*   Composer

## Installation

You can install the package via Composer:

```bash
composer require php-mcp/server
```

> **Note:** For Laravel applications, consider using the dedicated [`php-mcp/laravel-server`](https://github.com/php-mcp/laravel) package. It builds upon this core library, providing helpful integrations, configuration options, and Artisan commands specifically tailored for the Laravel framework.

## Getting Started: A Simple `stdio` Server

Here's a minimal example demonstrating how to expose a simple PHP class method as an MCP Tool using the `stdio` transport.

**1. Create your MCP Element Class:**

Create a file, for example, `src/MyMcpStuff.php`:

```php
<?php

namespace App;

use PhpMcp\Server\Attributes\McpTool;

class MyMcpStuff
{
    /**
     * A simple tool to add two numbers.
     *
     * @param int $a The first number.
     * @param int $b The second number.
     * @return int The sum of the two numbers.
     */
    #[McpTool(name: 'adder')]
    public function addNumbers(int $a, int $b): int
    {
        return $a + $b;
    }
}
```

**2. Create the Server Script:**

Create a script, e.g., `mcp-server.php`, in your project root:

```php
<?php

declare(strict_types=1);

use PhpMcp\Server\Server;

// Ensure your project's autoloader is included
require_once __DIR__ . '/vendor/autoload.php';
// If your MCP elements are in a specific namespace, ensure that's autoloaded too (e.g., via composer.json)

// Optional: Configure logging (defaults to STDERR)
// $logger = new MyPsrLoggerImplementation(...);

$server = Server::make()
    // Optional: ->withLogger($logger)
    // Optional: ->withCache(new MyPsrCacheImplementation(...))
    // Optional: ->withContainer(new MyPsrContainerImplementation(...))
    ->withBasePath(__DIR__) // Directory to start scanning for Attributes
    ->withScanDirectories(['src']) // Specific subdirectories to scan (relative to basePath)
    ->discover(); // Find all #[Mcp*] attributes

// Run the server using the stdio transport
$exitCode = $server->run('stdio');

exit($exitCode);
```

**3. Configure your MCP Client:**

Configure your MCP client (like Cursor, Claude Desktop, etc.) to connect using the `stdio` transport. This typically involves specifying the command to run the server script. For example, in Cursor's `.cursor/mcp.json`:

```json
{
    "mcpServers": {
        "my-php-server": {
            "command": "php",
            "args": [
                "/path/to/your/project/mcp-server.php"
            ]
        }
    }
}
```

Replace `/path/to/your/project/mcp-server.php` with the actual absolute path to your script.

Now, when you connect your client, it should discover the `adder` tool.

## Core Concepts

The primary ways to expose functionality through `php-mcp/server` are:

1.  **Attribute Discovery:** Decorating your PHP methods or invokable classes with specific Attributes (`#[McpTool]`, `#[McpResource]`, etc.). The server automatically discovers these during the `->discover()` process.
2.  **Manual Registration:** Using fluent methods (`->withTool()`, `->withResource()`, etc.) on the `Server` instance before running it.

### Attributes for Discovery

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

### The `Server` Fluent Interface

The `PhpMcp\Server\Server` class is the main entry point for configuring and running your MCP server. It provides a fluent interface (method chaining) to set up dependencies, parameters, and manually register elements.

*   **`Server::make(): self`**: Static factory method to create a new server instance. It initializes the server with default implementations for core services (Logger, Cache, Config, Container).
*   **`->withLogger(LoggerInterface $logger): self`**: Provide a PSR-3 compliant logger implementation.
    *   **If using the default `BasicContainer`:** This method replaces the default `StreamLogger` instance and updates the registration within the `BasicContainer`.
    *   **If using a custom container:** This method *only* sets an internal property on the `Server` instance. It **does not** affect the custom container. You should register your desired `LoggerInterface` directly within your container setup.
*   **`->withCache(CacheInterface $cache): self`**: Provide a PSR-16 compliant cache implementation.
    *   **If using the default `BasicContainer`:** This method replaces the default `FileCache` instance and updates the registration within the `BasicContainer`.
    *   **If using a custom container:** This method *only* sets an internal property on the `Server` instance. It **does not** affect the custom container. You should register your desired `CacheInterface` directly within your container setup.
*   **`->withContainer(ContainerInterface $container): self`**: Provide a PSR-11 compliant DI container.
    *   When called, the server will **use this container** for all dependency resolution, including its internal needs and instantiating your handler classes.
    *   **Crucially, you MUST ensure this container is configured to provide implementations for `LoggerInterface`, `CacheInterface`, and `ConfigurationRepositoryInterface`**, as the server relies on these.
    *   If not called, the server uses its internal `BasicContainer` with built-in defaults.
*   **`->withConfig(ConfigurationRepositoryInterface $config): self`**: Provide a custom configuration repository.
    *   **If using the default `BasicContainer`:** This method replaces the default `ArrayConfigurationRepository` instance and updates the registration within the `BasicContainer`.
    *   **If using a custom container:** This method *only* sets an internal property on the `Server` instance. It **does not** affect the custom container. You should register your desired `ConfigurationRepositoryInterface` directly within your container setup.
*   **`->withBasePath(string $path): self`**: Set the absolute base path for directory scanning during discovery. Defaults to the parent directory of `vendor/php-mcp/server`.
*   **`->withScanDirectories(array $dirs): self`**: Specify an array of directory paths *relative* to the `basePath` where the server should look for annotated classes/methods during discovery. Defaults to `['.', 'src/MCP']`.
*   **`->withExcludeDirectories(array $dirs): self`**: Specify an array of directory paths *relative* to the `basePath` to *exclude* from scanning during discovery. Defaults to common directories like `['vendor', 'tests', 'storage', 'cache', 'node_modules']`. Added directories are merged with defaults.
*   **`->withTool(array|string $handler, ?string $name = null, ?string $description = null): self`**: Manually registers a tool.
*   **`->withResource(array|string $handler, string $uri, ?string $name = null, ...): self`**: Manually registers a resource.
*   **`->withPrompt(array|string $handler, ?string $name = null, ?string $description = null): self`**: Manually registers a prompt.
*   **`->withResourceTemplate(array|string $handler, ?string $name = null, ..., string $uriTemplate, ...): self`**: Manually registers a resource template.
*   **`->discover(bool $cache = true): self`**: Initiates the discovery process. Scans the configured directories for attributes, builds the internal registry of MCP elements, and caches them using the provided cache implementation (unless `$cache` is false). **Note:** Manually registered elements are always added to the registry, regardless of discovery or caching.
*   **`->run(?string $transport = null): int`**: Starts the server's main processing loop using the specified transport.
    *   If `$transport` is `'stdio'` (or `null` when running in CLI), it uses the `StdioTransportHandler` to communicate over standard input/output.
    *   If `$transport` is `'http'` or `'reactphp'`, it throws an exception, as these transports needs to be integrated into an existing HTTP server loop (see Transports section).
    *   Returns the exit code (relevant for `stdio`).

### Dependency Injection

The `Server` relies on a PSR-11 `ContainerInterface` for two main purposes:

1.  **Resolving Server Dependencies:** The server itself needs instances of `LoggerInterface`, `CacheInterface`, and `ConfigurationRepositoryInterface` to function (e.g., for logging internal operations, caching discovered elements, reading configuration values).
2.  **Resolving Handler Dependencies:** When an MCP client calls a tool or reads a resource/prompt that maps to one of your attributed methods or a manually registered handler, the server uses the container to get an instance of the handler's class (e.g., `$container->get(MyHandlerClass::class)`). This allows your handler classes to use constructor injection for their own dependencies (like database connections, application services, etc.).

**Default Behavior (No `withContainer` Call):**

If you *do not* call `->withContainer()`, the server uses its internal `PhpMcp\Server\Defaults\BasicContainer`. This basic container comes pre-configured with default implementations:
*   `LoggerInterface` -> `PhpMcp\Server\Defaults\StreamLogger` (writes to `STDERR`)
*   `CacheInterface` -> `PhpMcp\Server\Defaults\FileCache` (writes to `../cache/mcp_cache` relative to the package directory)
*   `ConfigurationRepositoryInterface` -> `PhpMcp\Server\Defaults\ArrayConfigurationRepository` (uses built-in default configuration values)

In this default mode, you *can* use the `->withLogger()`, `->withCache()`, and `->withConfig()` methods to replace these defaults. These methods will update the instance used by the server and also update the registration within the internal `BasicContainer`.

**Using a Custom Container (`->withContainer(MyContainer $c)`):**

If you provide your own PSR-11 container instance using `->withContainer()`, the responsibility shifts entirely to you:

*   **You MUST ensure your container is configured to provide implementations for `LoggerInterface`, `CacheInterface`, and `ConfigurationRepositoryInterface`.** The server will attempt to fetch these using `$container->get(...)` and will fail if they are not available.
*   Your container will also be used to instantiate your handler classes, so ensure all their dependencies are also properly configured within your container.
*   When using a custom container, the `->withLogger()`, `->withCache()`, and `->withConfig()` methods on the `Server` instance become largely ineffective for modifying the dependencies the server *actually uses* during request processing, as the server will always defer to retrieving these services from *your provided container*. Configure these services directly in your container's setup.

Using the default `BasicContainer` is suitable for simple cases. For most applications, providing your own pre-configured PSR-11 container (from your framework or a library like PHP-DI) via `->withContainer()` is the recommended approach for proper dependency management.

### Configuration

The server's behavior can be customized through a configuration repository implementing `PhpMcp\Server\Contracts\ConfigurationRepositoryInterface`. You provide this using `->withConfig()`. If not provided, a default `PhpMcp\Server\Defaults\ArrayConfigurationRepository` is used.

Key configuration values (using dot notation) include:

*   `mcp.server.name`: (string) Server name for handshake.
*   `mcp.server.version`: (string) Server version for handshake.
*   `mcp.protocol_versions`: (array) Supported protocol versions (e.g., `['2024-11-05']`).
*   `mcp.pagination_limit`: (int) Default limit for listing elements.
*   `mcp.capabilities.tools.enabled`: (bool) Enable/disable the tools capability.
*   `mcp.capabilities.resources.enabled`: (bool) Enable/disable the resources capability.
*   `mcp.capabilities.resources.subscribe`: (bool) Enable/disable resource subscriptions.
*   `mcp.capabilities.prompts.enabled`: (bool) Enable/disable the prompts capability.
*   `mcp.capabilities.logging.enabled`: (bool) Enable/disable the `logging/setLevel` method.
*   `mcp.cache.ttl`: (int) Cache time-to-live in seconds.
*   `mcp.cache.prefix`: (string) Prefix for cache related to mcp.
*   `mcp.runtime.log_level`: (string) Default log level (used by default logger).

You can create your own implementation of the interface or pass an instance of `ArrayConfigurationRepository` populated with your overrides to `->withConfig()`. If a capability flag (e.g., `mcp.capabilities.tools.enabled`) is set to `false`, attempts by a client to use methods related to that capability (e.g., `tools/list`, `tools/call`) will result in a "Method not found" error.

### Transports

MCP defines how clients and servers exchange JSON-RPC messages. This package provides handlers for common transport mechanisms and allows for custom implementations.

#### Standard I/O (`stdio`)

The `PhpMcp\Server\Transports\StdioTransportHandler` handles communication over Standard Input (`STDIN`) and Standard Output (`STDOUT`). It uses `react/event-loop` and `react/stream` internally for non-blocking I/O, making it suitable for direct integration with clients that manage the server process lifecycle, like Cursor or Claude Desktop when configured to run a command. You activate it by calling `$server->run('stdio')` or simply `$server->run()` when executed in a CLI environment.

#### HTTP + Server-Sent Events (`http`)

The `PhpMcp\Server\Transports\HttpTransportHandler` implements the standard MCP HTTP binding. It uses Server-Sent Events (SSE) for server-to-client communication and standard HTTP POST requests for client-to-server messages. This handler is *not* run directly via `$server->run('http')`. Instead, you must integrate its logic into your own HTTP server built with a framework like Symfony, Laravel, or an asynchronous framework like ReactPHP.

> [!WARNING]
> **Server Environment Warning:** Standard synchronous PHP web server setups (like PHP's built-in server, Apache/Nginx without concurrent FPM processes, or `php artisan serve`) typically run **one PHP process per request**. This model **cannot reliably handle** the concurrent nature of HTTP+SSE, where one long-running GET request handles the SSE stream while other POST requests arrive to send messages. This will likely cause hangs or failed requests.

To use HTTP+SSE reliably, your PHP application **must** be served by an environment capable of handling multiple requests concurrently, such as:
*   Nginx + PHP-FPM or Apache + PHP-FPM (with multiple worker processes configured).
*   Asynchronous PHP Runtimes like ReactPHP, Amp, Swoole (e.g., via Laravel Octane), RoadRunner (e.g., via Laravel Octane), or FrankenPHP.

Additionally, ensure your web server and PHP-FPM (if used) configurations allow long-running scripts (`set_time_limit(0)` is recommended in your SSE handler) and do not buffer the `text/event-stream` response.

**Client ID Handling:** The server needs a reliable way to associate incoming POST requests with the correct persistent SSE connection state. Relying solely on session cookies can be problematic.
*   **Recommended Approach (Query Parameter):**
    1.  When the SSE connection is established, determine a unique `clientId` (e.g., session ID or generated UUID).
    2.  Generate the URL for the POST endpoint (where the client sends messages).
    3.  Append the `clientId` as a query parameter to this URL (e.g., `/mcp/message?clientId=UNIQUE_ID`).
    4.  Send this *complete URL* (including the query parameter) to the client via the initial `endpoint` SSE event.
    5.  In your HTTP controller handling the POST requests, retrieve the `clientId` directly from the query parameter (`$request->query('clientId')`).
    6.  Pass this explicit `clientId` to `$httpHandler->handleInput(...)`.

**Integration Steps (General):**
1.  **Configure Server:** Create and configure your `PhpMcp\Server\Server` instance (e.g., `$server = Server::make()->withLogger(...)->discover();`).
2.  **Instantiate Handler:** Get an instance of `HttpTransportHandler`, passing the configured `$server` instance to its constructor: `$httpHandler = new HttpTransportHandler($server);` (or use dependency injection configured to do this).
3.  **SSE Endpoint:** Create an endpoint (e.g., `/mcp/sse`) for GET requests. Set `Content-Type: text/event-stream` and keep the connection open.
4.  **POST Endpoint:** Create an endpoint (e.g., `/mcp/message`) for POST requests with `Content-Type: application/json`.
5.  **SSE Handler Logic:** Determine the `clientId`, use the `$httpHandler`, generate the POST URI *with* the `clientId` query parameter, call `$httpHandler->handleSseConnection(...)`, and ensure `$httpHandler->cleanupClient(...)` is called when the connection closes.
6.  **POST Handler Logic:** Retrieve the `clientId` from the query parameter, get the raw JSON request body, use the `$httpHandler`, call `$httpHandler->handleInput(...)` with the body and `clientId`, and return an appropriate HTTP response (e.g., 202 Accepted).

#### ReactPHP HTTP Transport (`reactphp`)

This package includes `PhpMcp\Server\Transports\ReactPhpHttpTransportHandler`, a concrete transport handler that integrates the core MCP HTTP+SSE logic with the ReactPHP ecosystem. It replaces potentially synchronous or blocking loops (often found in basic integrations of `HttpTransportHandler`) with ReactPHP's fully asynchronous, non-blocking event loop and stream primitives. Instantiate it by passing your configured `Server` instance: `$reactHandler = new ReactPhpHttpTransportHandler($server);`. This enables efficient handling of concurrent SSE connections within a ReactPHP-based application server. See the `samples/reactphp_http/server.php` example for a practical implementation.

#### Custom Transports

You can create your own transport handlers if `stdio` or `http` don't fit your specific needs (e.g., WebSockets, custom RPC mechanisms). Two main approaches exist:

1.  **Implement the Interface:** Create a class that implements `PhpMcp\Server\Contracts\TransportHandlerInterface`. This gives you complete control over the communication lifecycle.
2.  **Extend Existing Handlers:** Inherit from `PhpMcp\Server\Transports\StdioTransportHandler`, `PhpMcp\Server\Transports\HttpTransportHandler`, or `PhpMcp\Server\Transports\ReactPhpHttpTransportHandler`. Override specific methods to adapt the behavior (e.g., `sendResponse`, `handleSseConnection`, `cleanupClient`). Remember to call the parent constructor correctly if extending HTTP handlers: `parent::__construct($server)`. The `ReactPhpHttpTransportHandler` serves as a good example of extending `HttpTransportHandler`.

Examine the source code of the provided handlers to understand the interaction with the `Processor` and how to manage the request/response flow and client state.

## Advanced Usage & Recipes

Here are some examples of how to integrate `php-mcp/server` with common libraries and frameworks.

### Using Custom PSR Implementations

*   **Monolog (PSR-3 Logger):**
    ```php
    use Monolog\Logger;
    use Monolog\Handler\StreamHandler;
    use PhpMcp\Server\Server;

    // composer require monolog/monolog
    $log = new Logger('mcp-server');
    $log->pushHandler(new StreamHandler(__DIR__.'/mcp.log', Logger::DEBUG));

    $server = Server::make()
        ->withLogger($log)
        // ... other configurations
        ->discover()
        ->run();
    ```

*   **PSR-11 Container (Example with PHP-DI):**
    ```php
    use DI\ContainerBuilder;
    use PhpMcp\Server\Server;
    
    // composer require php-di/php-di
    $containerBuilder = new ContainerBuilder();
    // $containerBuilder->addDefinitions(...); // Add your app definitions
    $container = $containerBuilder->build();
    
    $server = Server::make()
        ->withContainer($container)
        // ... other configurations
        ->discover()
        ->run();
    ```

*   **Fine-grained Configuration:**
    Override default settings by providing a pre-configured `ArrayConfigurationRepository`:
    ```php
    use PhpMcp\Server\Defaults\ArrayConfigurationRepository;
    use PhpMcp\Server\Server;

    $configOverrides = [
        'mcp.server.name' => 'My Custom PHP Server',
        'mcp.capabilities.prompts.enabled' => false, // Disable prompts
        'mcp.discovery.directories' => ['src/Api/McpHandlers'], // Scan only specific dir
    ];

    $configRepo = new ArrayConfigurationRepository($configOverrides); 
    // Note: This replaces ALL defaults. Merge manually if needed:
    // $defaultConfig = new ArrayConfigurationRepository(); // Get defaults
    // $mergedConfigData = array_merge_recursive($defaultConfig->all(), $configOverrides);
    // $configRepo = new ArrayConfigurationRepository($mergedConfigData);

    $server = Server::make()
        ->withConfig($configRepo)
        // Ensure other PSR dependencies are provided if not using defaults
        // ->withLogger(...)->withCache(...)->withContainer(...)
        ->withBasePath(__DIR__)
        ->discover()
        ->run();
    ```

### HTTP+SSE Integration (Framework Examples)

*   **Symfony Controller Skeleton:**
    ```php
    <?php
    namespace App\Controller;

    use PhpMcp\Server\Transports\HttpTransportHandler;
    use Psr\Log\LoggerInterface;
    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpFoundation\StreamedResponse;
    use Symfony\Component\Routing\Annotation\Route;
    use RuntimeException;

    class McpController extends AbstractController
    {
        private readonly HttpTransportHandler $mcpHandler;
        private readonly LoggerInterface $logger;

        // Inject HttpTransportHandler directly.
        // The Symfony service definition for HttpTransportHandler MUST be configured
        // to receive the fully configured PhpMcp\Server\Server instance as an argument.
        public function __construct(
            private readonly HttpTransportHandler $mcpHandler,
            private readonly LoggerInterface $logger
        ) {}

        #[Route('/mcp', name: 'mcp_post', methods: ['POST'])]
        public function handlePost(Request $request): Response
        {
            $clientId = $request->query('clientId'); 
             if (! $clientId) { 
                 // Or: $session = $request->getSession(); $session->start(); $clientId = $session->getId();
                 return new Response('Missing clientId', 400);
             }

            if (! $request->isJson()) {
                return new Response('Content-Type must be application/json', 415);
            }
            $content = $request->getContent();
            if (empty($content)) {
                return new Response('Empty request body', 400);
            }

            // Ensure session is started if using session ID
            $session = $request->getSession();
            $session->start(); // Make sure session exists
            $clientId = $session->getId();

            try {
                $this->mcpHandler->handleInput($content, $clientId);
                return new Response(null, 202); // Accepted
            } catch (\JsonException $e) {
                return new Response('Invalid JSON: '.$e->getMessage(), 400);
            } catch (\Throwable $e) {
                $this->logger->error('MCP POST error', ['exception' => $e]);
                return new Response('Internal Server Error', 500);
            }
        }

        #[Route('/mcp/sse', name: 'mcp_sse', methods: ['GET'])]
        public function handleSse(Request $request): StreamedResponse
        {
            // Retrieve/generate clientId (e.g., from session or generate new one)
            $session = $request->getSession(); 
            $session->start(); 
            $clientId = $session->getId();
            // Or: $clientId = 'client_'.bin2hex(random_bytes(16));
            
            $this->logger->info('MCP SSE connection opening', ['client_id' => $clientId]);

            $response = new StreamedResponse(function () use ($clientId, $request) {
                try {
                    $postEndpointUri = $this->generateUrl('mcp_post', ['clientId' => $clientId], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
                    
                    // Use the handler's method to manage the SSE loop
                    $this->mcpHandler->handleSseConnection($clientId, $postEndpointUri);
                } catch (\Throwable $e) {
                        if (! ($e instanceof RuntimeException && str_contains($e->getMessage(), 'disconnected'))) {
                            $this->logger->error('SSE stream loop terminated unexpectedly', ['exception' => $e, 'clientId' => $clientId]);
                        }
                } finally {
                    // Ensure cleanup happens when the loop exits
                    $this->mcpHandler->cleanupClient($clientId);
                    $this->logger->info('SSE connection closed', ['client_id' => $clientId]);
                }
            });

            // Set headers for SSE
            $response->headers->set('Content-Type', 'text/event-stream');
            $response->headers->set('Cache-Control', 'no-cache');
            $response->headers->set('Connection', 'keep-alive');
            $response->headers->set('X-Accel-Buffering', 'no'); // Important for Nginx
            return $response;
        }
    }
    ```

### Resource, Tool, and Prompt Change Notifications

Clients may need to be notified if the available resources, tools, or prompts change *after* the initial connection and `initialize` handshake (e.g., due to dynamic configuration changes, file watching, etc.).

When your application detects such a change, retrieve the `Registry` instance (e.g., via `$server->getRegistry()` or DI) and call the appropriate method:

*   `$registry->notifyResourceChanged(string $uri)`: If a specific resource's content changed.
*   `$registry->notifyResourcesListChanged()`: If the list of available resources changed.
*   `$registry->notifyToolsListChanged()`: If the list of available tools changed.
*   `$registry->notifyPromptsListChanged()`: If the list of available prompts changed.

These methods trigger internal notifiers (configurable via `set*ChangedNotifier` methods on the registry). The active transport handler (especially `HttpTransportHandler` in its SSE loop) listens for these notifications and sends the corresponding MCP notification (`resources/didChange`, `resources/listChanged`, `tools/listChanged`, `prompts/listChanged`) to connected clients.

## Connecting MCP Clients

You can connect various MCP-compatible clients to servers built with this library. The connection method depends on the transport you are using (`stdio` or `http`).

**General Principles:**

*   **`stdio` Transport:** You typically provide the client with the command needed to execute your server script (e.g., `php /path/to/mcp-server.php`). The client manages the server process lifecycle.
*   **`http` Transport:** You provide the client with the URL of your SSE endpoint (e.g., `http://localhost:8080/mcp/sse`). The client connects to this URL, and the server (via the initial `endpoint` event) tells the client where to send POST requests.

**Client-Specific Instructions:**

*   **Cursor:**
    *   Open your User Settings (`Cmd/Ctrl + ,`), navigate to the `MCP` section, or directly edit your `.cursor/mcp.json` file.
    *   Add an entry under `mcpServers`:
        *   **For `stdio`:**
          ```json
          {
              "mcpServers": {
                  "my-php-server-stdio": { // Choose a unique name
                      "command": "php",
                      "args": [
                          "/full/path/to/your/project/mcp-server.php" // Use absolute path
                      ]
                  }
              }
          }
          ```
        *   **For `http`:** (Check Cursor's documentation for the exact format, likely involves a `url` field)
          ```json
          {
              "mcpServers": {
                  "my-php-server-http": { // Choose a unique name
                      "url": "http://localhost:8080/mcp/sse" // Your SSE endpoint URL
                  }
              }
          }
          ```

*   **Claude Desktop:**
    *   Go to Settings -> Connected Apps -> MCP Servers -> Add Server.
    *   **For `stdio`:** Select "Command" type, enter `php` in the command field, and the absolute path to your `mcp-server.php` script in the arguments field.
    *   **For `http`:** Select "URL" type and enter the full URL of your SSE endpoint (e.g., `http://localhost:8080/mcp/sse`).
    *   *(Refer to official Claude Desktop documentation for the most up-to-date instructions.)*

*   **Windsurf:**
    *   Connection settings are typically managed through its configuration.
    *   **For `stdio`:** Look for options to define a server using a command and arguments, similar to Cursor.
    *   **For `http+sse`:** Look for options to connect to an MCP server via a URL, providing your SSE endpoint.
    *   *(Refer to official Windsurf documentation for specific details.)*

## Examples

Working examples demonstrating different setups can be found in the [`samples/`](./samples/) directory:

*   [`samples/php_stdio/`](./samples/php_stdio/): Demonstrates a basic server using the `stdio` transport, suitable for direct command-line execution by clients.
*   [`samples/php_http/`](./samples/php_http/): Provides a basic example of integrating with a synchronous PHP HTTP server (e.g., using PHP's built-in server or Apache/Nginx with PHP-FPM). *Note: Requires careful handling of request lifecycles and SSE for full functionality.*
*   [`samples/reactphp_http/`](./samples/reactphp_http/): Shows how to integrate the `ReactPhpHttpTransportHandler` with [ReactPHP](https://reactphp.org/) to create an asynchronous HTTP+SSE server.

## Testing

This package uses [Pest](https://pestphp.com/) for testing.

1.  Install development dependencies:
    ```bash
    composer install --dev
    ```
2.  Run the test suite:
    ```bash
    composer test
    ```
3.  Run tests with code coverage reporting (requires Xdebug):
    ```bash
    composer test:coverage
    ```

## Contributing

Please see CONTRIBUTING.md for details (if it exists), but generally:

*   Report bugs or suggest features via GitHub Issues.
*   Submit pull requests for improvements. Please ensure tests pass and code style is maintained.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Support & Feedback

Please open an issue on the [GitHub repository](https://github.com/php-mcp/server) for bugs, questions, or feedback.
