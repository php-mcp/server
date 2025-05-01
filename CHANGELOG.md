# Changelog

All notable changes to `php-mcp/server` will be documented in this file.

## Release v1.0.0 - Initial Release

🚀 **Initial release of PHP MCP SERVER!**

This release introduces the core implementation of the Model Context Protocol (MCP) server for PHP applications. The goal is to provide a robust, flexible, and developer-friendly way to expose parts of your PHP application as MCP Tools, Resources, and Prompts, enabling standardized communication with AI assistants like Claude, Cursor, and others.

### ✨ Key Features:

*   **Attribute-Based Definitions:** Easily define MCP Tools (`#[McpTool]`), Resources (`#[McpResource]`, `#[McpResourceTemplate]`), and Prompts (`#[McpPrompt]`) using PHP 8 attributes directly on your methods.
*   **Automatic Metadata Inference:** Leverages method signatures (parameters, type hints) and DocBlocks (`@param`, `@return`, summaries) to automatically generate MCP schemas and descriptions, minimizing boilerplate.
*   **PSR Compliance:** Integrates seamlessly with standard PHP interfaces:
    *   `PSR-3` (LoggerInterface) for flexible logging.
    *   `PSR-11` (ContainerInterface) for dependency injection and class resolution.
    *   `PSR-16` (SimpleCacheInterface) for caching discovered elements and transport state.
*   **Automatic Discovery:** Scans configured directories to find and register your annotated MCP elements.
*   **Flexible Configuration:** Uses a configuration repository (`ConfigurationRepositoryInterface`) for fine-grained control over server behaviour, capabilities, and caching.
*   **Multiple Transports:**
    *   Built-in support for the `stdio` transport, ideal for command-line driven clients.
    *   Includes `HttpTransportHandler` components for building standard `http` (HTTP+SSE) transports (requires integration into an HTTP server).
    *   Provides `ReactPhpHttpTransportHandler` for seamless integration with asynchronous ReactPHP applications.
*   **Protocol Support:** Implements the `2024-11-05` version of the Model Context Protocol.
*   **Framework Agnostic:** Designed to work in vanilla PHP projects or integrated into any framework.

### 🚀 Getting Started

Please refer to the [README.md](README.md) for detailed installation instructions, usage examples, and core concepts. Sample implementations for `stdio` and `reactphp` are available in the `samples/` directory.

### ⚠️ Important Notes

*   When implementing the `http` transport using `HttpTransportHandler`, be aware of the critical server environment requirements detailed in the README regarding concurrent request handling for SSE. Standard synchronous PHP servers (like `php artisan serve` or basic Apache/Nginx setups) are generally **not suitable** without proper configuration for concurrency (e.g., PHP-FPM with multiple workers, Octane, Swoole, ReactPHP, RoadRunner, FrankenPHP).

### Future Plans

While this package focuses on the server implementation, future projects within the `php-mcp` organization may include client libraries and other utilities related to MCP in PHP.
