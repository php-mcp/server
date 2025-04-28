<?php

namespace PhpMcp\Server\Defaults;

use InvalidArgumentException;
use LogicException;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

/**
 * A simple PSR-3 logger that writes logs to a stream (file path or resource).
 * Inspired by Monolog\Handler\StreamHandler.
 */
class StreamLogger extends AbstractLogger
{
    protected const LOG_FORMAT = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";

    protected const DATE_FORMAT = 'Y-m-d H:i:s.u'; // Added microseconds for more precision

    /** @var resource|null Stream resource */
    protected $stream = null;

    /** @var string|null Stream URL path */
    protected ?string $url = null;

    /** @var int|null File permissions */
    protected ?int $filePermission; // Default: 0644

    /** @var bool Use file locking */
    protected bool $useLocking;

    /** @var string File open mode */
    protected string $fileOpenMode;

    /** @var true|null */
    private ?bool $dirCreated = null;

    /** @var string Current error message */
    private ?string $errorMessage = null;

    /** @var bool Flag indicating if a write operation is being retried */
    private bool $retrying = false;

    /** @var array<string, int> PSR-3 log levels mapped to severity integers */
    protected static array $levels = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    /** @var int Minimum log level severity for this handler */
    protected int $minimumLevelSeverity;

    /** @var string Channel name for the logger */
    protected string $channel;

    /**
     * @param  resource|string  $stream  Stream resource or file path.
     * @param  string  $minimumLevel  Minimum PSR-3 log level to handle.
     * @param  string  $channel  Logger channel name.
     * @param  int|null  $filePermission  Optional file permissions (defaults to 0644).
     * @param  bool  $useLocking  Enable file locking.
     * @param  string  $fileOpenMode  fopen mode.
     *
     * @throws InvalidArgumentException If stream is not a resource or string.
     * @throws InvalidArgumentException If minimumLevel is not a valid PSR-3 log level.
     */
    public function __construct(
        mixed $stream = STDOUT,
        string $minimumLevel = LogLevel::DEBUG,
        string $channel = 'mcp',
        ?int $filePermission = 0644,
        bool $useLocking = false,
        string $fileOpenMode = 'a'
    ) {
        if (! isset(static::$levels[$minimumLevel])) {
            throw new InvalidArgumentException("Invalid minimum log level specified: {$minimumLevel}");
        }
        $this->minimumLevelSeverity = static::$levels[$minimumLevel];
        $this->channel = $channel;

        if (is_resource($stream)) {
            $this->stream = $stream;
        } elseif (is_string($stream)) {
            $this->url = self::canonicalizePath($stream);
        } else {
            throw new InvalidArgumentException('A stream must be a resource or a string path.');
        }

        $this->fileOpenMode = $fileOpenMode;
        $this->filePermission = $filePermission;
        $this->useLocking = $useLocking;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param  mixed  $level  PSR-3 log level constant (e.g., LogLevel::INFO)
     * @param  string|Stringable  $message  Message to log
     * @param  array<mixed>  $context  Optional context data
     *
     * @throws RuntimeException If writing to stream fails.
     * @throws LogicException If stream URL is missing.
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (! isset(static::$levels[$level])) {
            // Silently ignore invalid levels? Or throw? PSR-3 says MUST accept any level.
            // For internal comparison, we need a valid level. Let's treat unknowns as debug?
            // Or maybe throw if it's not one of the standard strings?
            // For now, let's ignore if level is unknown for severity check.
            $logLevelSeverity = static::$levels[LogLevel::DEBUG]; // Fallback?
            // Alternative: Throw new \Psr\Log\InvalidArgumentException("Invalid log level: {$level}");
        } else {
            $logLevelSeverity = static::$levels[$level];
        }

        // Check minimum level
        if ($logLevelSeverity < $this->minimumLevelSeverity) {
            return;
        }

        // Ensure stream is open
        if (! is_resource($this->stream)) {
            if ($this->url === null || $this->url === '') {
                throw new LogicException('Missing stream url, the stream cannot be opened. This may be caused by a premature call to close().');
            }
            $this->createDir($this->url);
            $this->errorMessage = null;
            set_error_handler([$this, 'customErrorHandler']);
            try {
                $stream = fopen($this->url, $this->fileOpenMode);
                if ($this->filePermission !== null) {
                    @chmod($this->url, $this->filePermission);
                }
            } finally {
                restore_error_handler();
            }

            if (! is_resource($stream)) {
                $this->stream = null;
                throw new RuntimeException(sprintf('The stream "%s" could not be opened: %s', $this->url, $this->errorMessage ?? 'Unknown error'));
            }
            $this->stream = $stream;
        }

        // Format message
        // Normalize message
        $message = (string) $message;
        // Interpolate context
        $interpolatedMessage = $this->interpolate($message, $context);
        // Build record
        $record = [
            'message' => $interpolatedMessage,
            'context' => $context,
            'level' => $logLevelSeverity,
            'level_name' => strtoupper($level),
            'channel' => $this->channel,
            'datetime' => microtime(true), // Get precise time
            'extra' => [],
        ];
        // Format record
        $formattedMessage = $this->formatRecord($record);

        // Write to stream
        $stream = $this->stream;
        if ($this->useLocking) {
            flock($stream, LOCK_EX);
        }

        $this->errorMessage = null;
        set_error_handler([$this, 'customErrorHandler']);
        try {
            fwrite($stream, $formattedMessage);
        } finally {
            restore_error_handler();
        }

        if ($this->errorMessage !== null) {
            $error = $this->errorMessage;
            // Retry logic
            if (! $this->retrying && $this->url !== null && $this->url !== 'php://memory') {
                $this->retrying = true;
                $this->close();
                $this->log($level, $message, $context); // Retry the original message and context
                $this->retrying = false; // Reset after retry attempt

                return;
            }
            // If retry also failed or not applicable
            throw new RuntimeException(sprintf('Could not write to stream "%s": %s', $this->url ?? 'Resource', $error));
        }

        $this->retrying = false;
        if ($this->useLocking) {
            flock($stream, LOCK_UN);
        }
    }

    /**
     * Closes the stream.
     */
    public function close(): void
    {
        if ($this->url !== null && is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->dirCreated = null; // Reset dir creation status
    }

    /**
     * Interpolates context values into the message placeholders.
     * Basic implementation.
     */
    protected function interpolate(string $message, array $context): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $val) {
            if ($val === null || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements['{'.$key.'}'] = $val;
            } elseif (is_object($val)) {
                $replacements['{'.$key.'}'] = '[object '.get_class($val).']';
            } else {
                $replacements['{'.$key.'}'] = '['.gettype($val).']';
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Formats a log record into a string for writing.
     */
    protected function formatRecord(array $record): string
    {
        $vars = $record;
        $vars['datetime'] = date(static::DATE_FORMAT, (int) $record['datetime']);
        // Format context array for log string
        $vars['context'] = ! empty($record['context']) ? json_encode($record['context']) : '';
        // Format extra array (if used)
        $vars['extra'] = ! empty($record['extra']) ? json_encode($record['extra']) : '';

        $output = static::LOG_FORMAT;
        foreach ($vars as $var => $val) {
            if (str_contains($output, '%'.$var.'%')) {
                $output = str_replace('%'.$var.'%', (string) $val, $output);
            }
        }

        // Remove leftover placeholders
        $output = preg_replace('/%(?:[a-zA-Z0-9_]+)%/', '', $output) ?? $output;

        return $output;
    }

    // --- Stream/File Handling Helpers ---

    /**
     * Custom error handler to capture stream operation errors.
     */
    private function customErrorHandler(int $code, string $msg): bool
    {
        $this->errorMessage = preg_replace('{^(fopen|mkdir|fwrite|chmod)\(.*?\): }U', '', $msg); // Added chmod

        return true;
    }

    /**
     * Creates the directory for the stream if it doesn't exist.
     */
    private function createDir(string $url): void
    {
        if ($this->dirCreated === true) {
            return;
        }

        $dir = $this->getDirFromStream($url);
        if ($dir !== null && ! is_dir($dir)) {
            $this->errorMessage = null;
            set_error_handler([$this, 'customErrorHandler']);
            $status = mkdir($dir, 0777, true); // Use default permissions, let system decide
            restore_error_handler();

            if ($status === false && ! is_dir($dir)) { // Check again if directory exists after race condition
                throw new RuntimeException(sprintf('Could not create directory "%s": %s', $dir, $this->errorMessage ?? 'Unknown error'));
            }
        }
        $this->dirCreated = true;
    }

    /**
     * Gets the directory path from a stream URL/path.
     */
    private function getDirFromStream(string $stream): ?string
    {
        if (str_starts_with($stream, 'file://')) {
            return dirname(substr($stream, 7));
        }
        // Check if it looks like a path without scheme
        if (! str_contains($stream, '://')) {
            return dirname($stream);
        }

        // Other schemes (php://stdout, etc.) don't have a directory
        return null;
    }

    /**
     * Canonicalizes a path (turns relative into absolute).
     */
    public static function canonicalizePath(string $path): string
    {
        $prefix = '';
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
            $prefix = 'file://';
        }

        // If it contains a scheme or is already absolute (Unix/Windows/UNC)
        if (str_contains($path, '://') || str_starts_with($path, '/') || preg_match('{^[a-zA-Z]:[/\\]}', $path) || str_starts_with($path, '\\')) {
            return $prefix.$path;
        }

        // Turn relative path into absolute
        $absolutePath = getcwd();
        if ($absolutePath === false) {
            throw new RuntimeException('Could not determine current working directory.');
        }
        $path = $absolutePath.'/'.$path;

        // Basic path normalization (remove . and ..)
        // This is a simplified approach
        $path = preg_replace('{[/\\]\.\.?([/\\]|$)}', '/', $path);

        return $prefix.$path;
    }

    /**
     * Closes the stream on destruction.
     */
    public function __destruct()
    {
        $this->close();
    }
}
