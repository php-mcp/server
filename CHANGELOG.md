# Changelog

All notable changes to `php-mcp/server` will be documented in this file.

## v3.2.2 - 2025-07-09

### What's Changed

* Fix Architecture graph by @szepeviktor in https://github.com/php-mcp/server/pull/42
* Fix: Correctly handle invokable class tool handlers by @CodeWithKyrian in https://github.com/php-mcp/server/pull/47

### New Contributors

* @szepeviktor made their first contribution in https://github.com/php-mcp/server/pull/42

**Full Changelog**: https://github.com/php-mcp/server/compare/3.2.1...3.2.2

## v3.2.1 - 2025-06-30

### What's Changed

* feat:  use callable instead of Closure|array|string for handler type by @CodeWithKyrian in https://github.com/php-mcp/server/pull/41

**Full Changelog**: https://github.com/php-mcp/server/compare/3.2.0...3.2.1

## v3.2.0 - 2025-06-30

### What's Changed

* fix: resolve cache session handler index inconsistencies by @CodeWithKyrian in https://github.com/php-mcp/server/pull/36
* feat: Add comprehensive callable handler support for closures, static methods, and invokable classes by @CodeWithKyrian in https://github.com/php-mcp/server/pull/38
* feat: Enhanced Completion Providers with Values and Enum Support by @CodeWithKyrian in https://github.com/php-mcp/server/pull/40

### Upgrade Guide

If you're using the `CompletionProvider` attribute with the named `providerClass` parameter, consider updating to the new `provider` parameter for consistency:

```php
// Before (still works)
#[CompletionProvider(providerClass: UserProvider::class)]

// After (recommended)
#[CompletionProvider(provider: UserProvider::class)]



```
The old `providerClass` parameter continues to work for backward compatibility, but may be dropped in a future major version release.

**Full Changelog**: https://github.com/php-mcp/server/compare/3.1.1...3.2.0

## v3.1.1 - 2025-06-26

### What's Changed

* Fix: implement proper MCP protocol version negotiation by @CodeWithKyrian in https://github.com/php-mcp/server/pull/35

**Full Changelog**: https://github.com/php-mcp/server/compare/3.1.0...3.1.1

## v3.1.0 - 2025-06-25

### What's Changed

* Refactor: expose session garbage collection method for integration by @CodeWithKyrian in https://github.com/php-mcp/server/pull/31
* feat: add instructions in server initialization result by @CodeWithKyrian in https://github.com/php-mcp/server/pull/32
* fix(cache): handle missing session in index for CacheSessionHandler by @CodeWithKyrian in https://github.com/php-mcp/server/pull/33

**Full Changelog**: https://github.com/php-mcp/server/compare/3.0.2...3.1.0

## v3.0.2 - 2025-06-25

### What's Changed

* fix: Registry cache clearing bug preventing effective caching by @CodeWithKyrian in https://github.com/php-mcp/server/pull/29
* Fix ServerBuilder error handling for manual element registration by @CodeWithKyrian in https://github.com/php-mcp/server/pull/30

**Full Changelog**: https://github.com/php-mcp/server/compare/3.0.1...3.0.2

## v3.0.1 - 2025-06-24

### What's Changed

* Fix validation failure for MCP tools without parameters by @CodeWithKyrian in https://github.com/php-mcp/server/pull/28

**Full Changelog**: https://github.com/php-mcp/server/compare/3.0.0...3.0.1

## v3.0.0 - 2025-06-21

This release brings support for the latest MCP protocol version along with enhanced schema generation, new transport capabilities, and streamlined APIs.

### ✨ New Features

* **StreamableHttpServerTransport**: New transport with resumability, event sourcing, and JSON response mode for production deployments
* **Smart Schema Generation**: Automatic JSON schema generation from method signatures with optional `#[Schema]` attribute enhancements
* **Completion Providers**: `#[CompletionProvider]` attribute for auto-completion in resource templates and prompts
* **Batch Request Processing**: Full support for JSON-RPC 2.0 batch requests
* **Enhanced Session Management**: Multiple session backends (array, cache, custom) with persistence and garbage collection

### 🔥 Breaking Changes

* **Schema Package Integration**: Now uses `php-mcp/schema` package for all DTOs, requests, responses, and content types
* **Session Management**: `ClientStateManager` replaced with `SessionManager` and `Session` classes
* **Component Reorganization**: `Support\*` classes moved to `Utils\*` namespace
* **Request Processing**: `RequestHandler` renamed to `Dispatcher`

*Note: Most of these changes are internal and won't affect your existing MCP element definitions and handlers.*

### 🔧 Enhanced Features

* **Improved Schema System**: The `#[Schema]` attribute can now be used at both method-level and parameter-level (previously parameter-level only)
* **Better Error Handling**: Enhanced JSON-RPC error responses with proper status codes
* **PSR-20 Clock Interface**: Time management with `SystemClock` implementation
* **Event Store Interface**: Pluggable event storage for resumable connections

### 📦 Dependencies

* Now requires `php-mcp/schema` ^1.0
* Enhanced PSR compliance (PSR-3, PSR-11, PSR-16, PSR-20)

### 🚧 Migration Guide

#### Capabilities Configuration

**Before:**

```php
->withCapabilities(Capabilities::forServer(
    resourcesEnabled: true,
    promptsEnabled: true,
    toolsEnabled: true,
    resourceSubscribe: true
))








```
**After:**

```php
->withCapabilities(ServerCapabilities::make(
    resources: true,
    prompts: true,
    tools: true,
    resourcesSubscribe: true
))








```
#### Transport Upgrade (Optional)

For production HTTP deployments, consider upgrading to the new `StreamableHttpServerTransport`:

**Before:**

```php
$transport = new HttpServerTransport(host: '127.0.0.1', port: 8080);








```
**After:**

```php
$transport = new StreamableHttpServerTransport(host: '127.0.0.1',  port: 8080);








```
### 📚 Documentation

* Complete README rewrite with comprehensive examples and deployment guides
* New production deployment section covering VPS, Docker, and SSL setup
* Enhanced schema generation documentation
* Migration guide for v2.x users

**Full Changelog**: https://github.com/php-mcp/server/compare/2.3.1...3.0.0

## v2.3.1 - 2025-06-13

### What's Changed

* Streamline Registry Notifications and Add Discovery Suppression Support by @CodeWithKyrian in https://github.com/php-mcp/server/pull/22

**Full Changelog**: https://github.com/php-mcp/server/compare/2.3.0...2.3.1

## v2.3.0 - 2025-06-12

### What's Changed

* Fix: Require react/promise ^3.0 for Promise API Compatibility by @CodeWithKyrian in https://github.com/php-mcp/server/pull/18
* Fix: Correct object serialization in FileCache using serialize/unserialize by @CodeWithKyrian in https://github.com/php-mcp/server/pull/19
* check the the header X-Forwarded-Proto for scheme by @bangnokia in https://github.com/php-mcp/server/pull/14
* Feat: Improve HttpServerTransport Extensibility via Protected Methods by @CodeWithKyrian in https://github.com/php-mcp/server/pull/20

### New Contributors

* @bangnokia made their first contribution in https://github.com/php-mcp/server/pull/14

**Full Changelog**: https://github.com/php-mcp/server/compare/2.2.1...2.3.0

## v2.2.1 - 2025-06-07

### What's Changed

* Fix tool name generation for invokable classes with MCP attributes by @CodeWithKyrian in https://github.com/php-mcp/server/pull/13

**Full Changelog**: https://github.com/php-mcp/server/compare/2.2.0...2.2.1

## v2.2.0 - 2025-06-03

### What's Changed

* feat(pagination): Added configuration for a server-wide pagination limit, enabling more controlled data retrieval for list-based MCP operations. This limit is utilized by the `RequestProcessor`.
* feat(handlers): Introduced `HandlerResolver` to provide more robust validation and resolution mechanisms for MCP element handlers, improving the reliability of element registration and invocation.
* refactor(server): Modified the server listening mechanism to allow initialization and transport binding without an immediately blocking event loop. This enhances flexibility for embedding the server or managing its lifecycle in diverse application environments.
* refactor(core): Performed general cleanup and enhancements to the internal architecture and dependencies, contributing to improved code maintainability and overall system stability.

**Full Changelog**: https://github.com/php-mcp/server/compare/2.1.0...2.2.0

## v2.1.0 - 2025-05-17

### What's Changed

* feat(schema): add Schema attributes and enhance DocBlock array type parsing by @CodeWithKyrian in https://github.com/php-mcp/server/pull/8

**Full Changelog**: https://github.com/php-mcp/server/compare/2.0.1...2.1.0

## PHP MCP Server v2.0.1 (HotFix) - 2025-05-11

### What's Changed

* Fix: Ensure react/http is a runtime dependency for HttpServerTransport by @CodeWithKyrian in https://github.com/php-mcp/server/pull/7

**Full Changelog**: https://github.com/php-mcp/server/compare/2.0.0...2.0.1

## PHP MCP Server v2.0.0 - 2025-05-11

This release marks a significant architectural refactoring of the package, aimed at improving modularity, testability, flexibility, and aligning its structure more closely with the `php-mcp/client` library. The core functionality remains, but the way servers are configured, run, and integrated has fundamentally changed.

### What's Changed

#### Core Architecture Overhaul

* **Decoupled Design:** The server core logic is now separated from the transport (network/IO) layer.
  
  * **`ServerBuilder`:** A new fluent builder (`Server::make()`) is the primary way to configure server identity, dependencies (Logger, Cache, Container, Loop), capabilities, and manually registered elements.
  * **`Server` Object:** The main `Server` class, created by the builder, now holds the configured core components (`Registry`, `Processor`, `ClientStateManager`, `Configuration`) but is transport-agnostic itself.
  * **`ServerTransportInterface`:** A new event-driven interface defines the contract for server-side transports (Stdio, Http). Transports are now responsible solely for listening and raw data transfer, emitting events for lifecycle and messages.
  * **`Protocol`:** A new internal class acts as a bridge, listening to events from a bound `ServerTransportInterface` and coordinating interactions with the `Processor` and `ClientStateManager`.
  
* **Explicit Server Execution:**
  
  * The old `$server->run(?string)` method is **removed**.
  * **`$server->listen(ServerTransportInterface $transport)`:** Introduced as the primary way to start a *standalone* server. It binds the `Protocol` to the provided transport, starts the listener, and runs the event loop (making it a blocking call).
  

#### Discovery and Caching Refinements

* **Explicit Discovery:** Attribute discovery is no longer triggered automatically during `build()`. You must now explicitly call `$server->discover(basePath: ..., scanDirs: ...)` *after* building the server instance if you want to find elements via attributes.
  
* **Caching Behavior:**
  
  * Only *discovered* elements are eligible for caching. Manually registered elements (via `ServerBuilder->with*` methods) are **never cached**.
  * The `Registry` attempts to load discovered elements from cache upon instantiation (during `ServerBuilder::build()`).
  * Calling `$server->discover()` will first clear any previously discovered/cached elements from the registry before scanning. It then saves the *newly discovered* results to the cache if enabled (`saveToCache: true`).
  * `Registry` cache methods renamed for clarity: `saveDiscoveredElementsToCache()` and `clearDiscoveredElements()`.
  * `Registry::isLoaded()` renamed to `discoveryRanOrCached()` for better clarity.
  
* **Manual vs. Discovered Precedence:** If an element is registered both manually and found via discovery/cache with the same identifier (name/URI), the **manually registered version always takes precedence**.
  

#### Dependency Injection and Configuration

* **`ConfigurationRepositoryInterface` Removed:** This interface and its default implementation (`ArrayConfigurationRepository`) have been removed.
* **`Configuration` Value Object:** A new `PhpMcp\Server\Configuration` readonly value object bundles core dependencies (Logger, Loop, Cache, Container, Server Info, Capabilities, TTLs) assembled by the `ServerBuilder`.
* **Simplified Dependencies:** Core components (`Registry`, `Processor`, `ClientStateManager`, `DocBlockParser`, `Discoverer`) now have simpler constructors, accepting direct dependencies.
* **PSR-11 Container Role:** The container provided via `ServerBuilder->withContainer()` (or the default `BasicContainer`) is now primarily used by the `Processor` to resolve *user-defined handler classes* and their dependencies.
* **Improved `BasicContainer`:** The default DI container (`PhpMcp\Server\Defaults\BasicContainer`) now supports simple constructor auto-wiring.
* **`ClientStateManager` Default Cache:** If no `CacheInterface` is provided to the `ClientStateManager`, it now defaults to an in-memory `PhpMcp\Server\Defaults\ArrayCache`.

#### Schema Generation and Validation

* **Removed Optimistic String Format Inference:** The `SchemaGenerator` no longer automatically infers JSON Schema `format` keywords (like "date-time", "email") for string parameters. This makes default schemas less strict, avoiding validation issues for users with simpler string formats. Specific format validation should now be handled within tool/resource methods or via future explicit schema annotation features.
* **Improved Tool Call Validation Error Messages:** When `tools/call` parameters fail schema validation, the JSON-RPC error response now includes a more informative summary message detailing the specific validation failures, in addition to the structured error data.

#### Transports

* **New Implementations:** Introduced `PhpMcp\Server\Transports\StdioServerTransport` and `PhpMcp\Server\Transports\HttpServerTransport`, both implementing `ServerTransportInterface`.
  
  * `StdioServerTransport` constructor now accepts custom input/output stream resources, improving testability and flexibility (defaults to `STDIN`/`STDOUT`).
  * `HttpServerTransport` constructor now accepts an array of request interceptor callables for custom request pre-processing (e.g., authentication), and also takes `host`, `port`, `mcpPathPrefix`, and `sslContext` for server configuration.
  
* **Windows `stdio` Limitation:** `StdioServerTransport` now throws a `TransportException` if instantiated with default `STDIN`/`STDOUT` on Windows, due to PHP's limitations with non-blocking pipes, guiding users to `WSL` or `HttpServerTransport`.
  
* **Aware Interfaces:** Transports can implement `LoggerAwareInterface` and `LoopAwareInterface` to receive the configured Logger and Loop instances when `$server->listen()` is called.
  
* **Removed:** The old `StdioTransportHandler`, `HttpTransportHandler`, and `ReactPhpHttpTransportHandler` classes.
  

#### Capabilities Configuration

* **`Model\Capabilities` Class:** Introduced a new `PhpMcp\Server\Model\Capabilities` value object (created via `Capabilities::forServer(...)`) to explicitly configure and represent server capabilities.

#### Exception Handling

* **`McpServerException`:** Renamed the base exception from `McpException` to `PhpMcp\Server\Exception\McpServerException`.
* **New Exception Types:** Added more specific exceptions: `ConfigurationException`, `DiscoveryException`, `DefinitionException`, `TransportException`, `ProtocolException`.

#### Fixes

* Fixed `StdioServerTransport` not cleanly exiting on `Ctrl+C` due to event loop handling.
* Fixed `TypeError` in `JsonRpc\Response` for parse errors with `null` ID.
* Corrected discovery caching logic for explicit `discover()` calls.
* Improved `HttpServerTransport` robustness for initial SSE event delivery and POST body handling.
* Ensured manual registrations correctly take precedence over discovered/cached elements with the same identifier.

#### Internal Changes

* Introduced `LoggerAwareInterface` and `LoopAwareInterface` for dependency injection into transports.
* Refined internal event handling between transport implementations and the `Protocol`.
* Renamed `TransportState` to `ClientStateManager` and introduced a `ClientState` Value Object.

#### Documentation and Examples

* Significantly revised `README.md` to reflect the new architecture, API, discovery flow, transport usage, and configuration.
* Added new and updated examples for standalone `stdio` and `http` servers, demonstrating discovery, manual registration, custom dependency injection, complex schemas, and environment variable usage.

### Breaking Changes

This is a major refactoring with significant breaking changes:

1. **`Server->run()` Method Removed:** Replace calls to `$server->run('stdio')` with:
   
    ```php
    $transport = new StdioServerTransport();
   // Optionally call $server->discover(...) first
   $server->listen($transport);
   
   
   
   
   
   
   
   
   
   
   
   
   
   
   
    ```
   The `http` and `reactphp` options for `run()` were already invalid and are fully removed.
   
2. **Configuration (`ConfigurationRepositoryInterface` Removed):** Configuration is now handled via the `Configuration` VO assembled by `ServerBuilder`. Remove any usage of the old `ConfigurationRepositoryInterface`. Core settings like server name/version are set via `withServerInfo`, capabilities via `withCapabilities`.
   
3. **Dependency Injection:**
   
   * If using `ServerBuilder->withContainer()` with a custom PSR-11 container, that container is now only responsible for resolving *your application's handler classes* and their dependencies.
   * Core server dependencies (Logger, Cache, Loop) **must** be provided explicitly to the `ServerBuilder` using `withLogger()`, `withCache()`, `withLoop()` or rely on the builder's defaults.
   
4. **Transport Handlers Replaced:**
   
   * `StdioTransportHandler`, `HttpTransportHandler`, `ReactPhpHttpTransportHandler` are **removed**.
   * Use `new StdioServerTransport()` or `new HttpServerTransport(...)` and pass them to `$server->listen()`.
   * Constructor signatures and interaction patterns have changed.
   
5. **`Registry` Cache Methods Renamed:** `saveElementsToCache` is now `saveDiscoveredElementsToCache`, and `clearCache` is now `clearDiscoveredElements`. Their behavior is also changed to only affect discovered elements.
   
6. **Core Component Constructors:** The constructors for `Registry`, `Processor`, `ClientStateManager` (previously `TransportState`), `Discoverer`, `DocBlockParser` have changed. Update any direct instantiations (though typically these are managed internally).
   
7. **Exception Renaming:** `McpException` is now `McpServerException`. Update `catch` blocks accordingly.
   
8. **Default Null Logger:** Logging is effectively disabled by default. Provide a logger via `ServerBuilder->withLogger()` to enable it.
   
9. **Schema Generation:** Automatic string `format` inference (e.g., "date-time") removed from `SchemaGenerator`. String parameters are now plain strings in the schema unless a more advanced format definition mechanism is used in the future.
   

### Deprecations

* (None introduced in this refactoring, as major breaking changes were made directly).

**Full Changelog**: https://github.com/php-mcp/server/compare/1.1.0...2.0.0

## PHP MCP Server v1.1.0 - 2025-05-01

### Added

* **Manual Element Registration:** Added fluent methods `withTool()`, `withResource()`, `withPrompt()`, and `withResourceTemplate()` to the `Server` class. This allows programmatic registration of MCP elements as an alternative or supplement to attribute discovery. Both `[ClassName::class, 'methodName']` array handlers and invokable class string handlers are supported.
* **Invokable Class Attribute Discovery:** The server's discovery mechanism now supports placing `#[Mcp*]` attributes directly on invokable PHP class definitions (classes with a public `__invoke` method). The `__invoke` method will be used as the handler.
* **Discovery Path Configuration:** Added `withBasePath()`, `withScanDirectories()`, and `withExcludeDirectories()` methods to the `Server` class for finer control over which directories are scanned during attribute discovery.

### Changed

* **Dependency Injection:** Refactored internal dependency management. Core server components (`Processor`, `Registry`, `ClientStateManager`, etc.) now resolve `LoggerInterface`, `CacheInterface`, and `ConfigurationRepositoryInterface` Just-In-Time from the provided PSR-11 container. See **Breaking Changes** for implications.
* **Default Logging Behavior:** Logging is now **disabled by default**. To enable logging, provide a `LoggerInterface` implementation via `withLogger()` (when using the default container) or by registering it within your custom PSR-11 container.
* **Transport Handler Constructors:** Transport Handlers (e.g., `StdioTransportHandler`, `HttpTransportHandler`) now primarily accept the `Server` instance in their constructor, simplifying their instantiation.

### Fixed

* Prevented potential "Constant STDERR not defined" errors in non-CLI environments by changing the default logger behavior (see Changed section).

### Updated

* Extensively updated `README.md` to document manual registration, invokable class discovery, the dependency injection overhaul, discovery path configuration, transport handler changes, and the new default logging behavior.

### Breaking Changes

* **Dependency Injection Responsibility:** Due to the DI refactoring, if you provide a custom PSR-11 container using `withContainer()`, you **MUST** ensure that your container is configured to provide implementations for `LoggerInterface`, `CacheInterface`, and `ConfigurationRepositoryInterface`. The server relies on being able to fetch these from the container.
* **`withLogger/Cache/Config` Behavior with Custom Container:** When a custom container is provided via `withContainer()`, calls to `->withLogger()`, `->withCache()`, or `->withConfig()` on the `Server` instance will **not** override the services resolved from *your* container during runtime. Configuration for these core services must be done directly within your custom container setup.
* **Transport Handler Constructor Signatures:** The constructor signatures for `StdioTransportHandler`, `HttpTransportHandler`, and `ReactPhpHttpTransportHandler` have changed. They now primarily require the `Server` instance. Update any direct instantiations of these handlers accordingly.

**Full Changelog**: https://github.com/php-mcp/server/compare/1.0.0...1.1.0

## Release v1.0.0 - Initial Release

🚀 **Initial release of PHP MCP SERVER!**

This release introduces the core implementation of the Model Context Protocol (MCP) server for PHP applications. The goal is to provide a robust, flexible, and developer-friendly way to expose parts of your PHP application as MCP Tools, Resources, and Prompts, enabling standardized communication with AI assistants like Claude, Cursor, and others.

### ✨ Key Features:

* **Attribute-Based Definitions:** Easily define MCP Tools (`#[McpTool]`), Resources (`#[McpResource]`, `#[McpResourceTemplate]`), and Prompts (`#[McpPrompt]`) using PHP 8 attributes directly on your methods.
  
* **Automatic Metadata Inference:** Leverages method signatures (parameters, type hints) and DocBlocks (`@param`, `@return`, summaries) to automatically generate MCP schemas and descriptions, minimizing boilerplate.
  
* **PSR Compliance:** Integrates seamlessly with standard PHP interfaces:
  
  * `PSR-3` (LoggerInterface) for flexible logging.
  * `PSR-11` (ContainerInterface) for dependency injection and class resolution.
  * `PSR-16` (SimpleCacheInterface) for caching discovered elements and transport state.
  
* **Automatic Discovery:** Scans configured directories to find and register your annotated MCP elements.
  
* **Flexible Configuration:** Uses a configuration repository (`ConfigurationRepositoryInterface`) for fine-grained control over server behaviour, capabilities, and caching.
  
* **Multiple Transports:**
  
  * Built-in support for the `stdio` transport, ideal for command-line driven clients.
  * Includes `HttpTransportHandler` components for building standard `http` (HTTP+SSE) transports (requires integration into an HTTP server).
  * Provides `ReactPhpHttpTransportHandler` for seamless integration with asynchronous ReactPHP applications.
  
* **Protocol Support:** Implements the `2024-11-05` version of the Model Context Protocol.
  
* **Framework Agnostic:** Designed to work in vanilla PHP projects or integrated into any framework.
  

### 🚀 Getting Started

Please refer to the [README.md](README.md) for detailed installation instructions, usage examples, and core concepts. Sample implementations for `stdio` and `reactphp` are available in the `samples/` directory.

### ⚠️ Important Notes

* When implementing the `http` transport using `HttpTransportHandler`, be aware of the critical server environment requirements detailed in the README regarding concurrent request handling for SSE. Standard synchronous PHP servers (like `php artisan serve` or basic Apache/Nginx setups) are generally **not suitable** without proper configuration for concurrency (e.g., PHP-FPM with multiple workers, Octane, Swoole, ReactPHP, RoadRunner, FrankenPHP).

### Future Plans

While this package focuses on the server implementation, future projects within the `php-mcp` organization may include client libraries and other utilities related to MCP in PHP.
