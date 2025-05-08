# Changelog

All notable changes to `php-mcp/server` will be documented in this file.

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
