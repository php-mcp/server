<?php

declare(strict_types=1);

namespace PhpMcp\Server\Exception;

/**
 * Exception related to invalid MCP element definitions (Tool, Resource, etc.).
 *
 * Can occur during manual registration, discovery, or validation.
 */
class DefinitionException extends McpServerException
{
    // No specific JSON-RPC code, internal server issue.
}
