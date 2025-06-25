<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use PhpMcp\Server\Contracts\SessionHandlerInterface;
use PhpMcp\Server\Contracts\SessionInterface;

class Session implements SessionInterface
{
    /**
     * @var array<string, mixed> Stores all session data.
     * Keys are snake_case by convention for MCP-specific data.
     *
     * Official keys are:
     * - initialized: bool
     * - client_info: array|null
     * - protocol_version: string|null
     * - subscriptions: array<string, bool>
     * - message_queue: array<string>
     * - log_level: string|null
     */
    protected array $data = [];

    public function __construct(
        protected SessionHandlerInterface $handler,
        protected string $id = '',
        ?array $data = null
    ) {
        if (empty($this->id)) {
            $this->id = $this->generateId();
        }

        if ($data !== null) {
            $this->hydrate($data);
        } elseif ($sessionData = $this->handler->read($this->id)) {
            $this->data = json_decode($sessionData, true) ?? [];
        }
    }

    /**
     * Retrieve an existing session instance from handler or return null if session doesn't exist
     */
    public static function retrieve(string $id, SessionHandlerInterface $handler): ?SessionInterface
    {
        $sessionData = $handler->read($id);

        if (!$sessionData) {
            return null;
        }

        $data = json_decode($sessionData, true);
        if ($data === null) {
            return null;
        }

        return new static($handler, $id, $data);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getHandler(): SessionHandlerInterface
    {
        return $this->handler;
    }

    public function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function save(): void
    {
        $this->handler->write($this->id, json_encode($this->data));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = explode('.', $key);
        $data = $this->data;

        foreach ($key as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return $default;
            }
        }

        return $data;
    }

    public function set(string $key, mixed $value, bool $overwrite = true): void
    {
        $segments = explode('.', $key);
        $data = &$this->data;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($data[$segment]) || !is_array($data[$segment])) {
                $data[$segment] = [];
            }
            $data = &$data[$segment];
        }

        $lastKey = array_shift($segments);
        if ($overwrite || !isset($data[$lastKey])) {
            $data[$lastKey] = $value;
        }
    }

    public function has(string $key): bool
    {
        $key = explode('.', $key);
        $data = $this->data;

        foreach ($key as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (is_object($data) && isset($data->{$segment})) {
                $data = $data->{$segment};
            } else {
                return false;
            }
        }

        return true;
    }

    public function forget(string $key): void
    {
        $segments = explode('.', $key);
        $data = &$this->data;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($data[$segment]) || !is_array($data[$segment])) {
                $data[$segment] = [];
            }
            $data = &$data[$segment];
        }

        $lastKey = array_shift($segments);
        if (isset($data[$lastKey])) {
            unset($data[$lastKey]);
        }
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function hydrate(array $attributes): void
    {
        $this->data = array_merge(
            [
                'initialized' => false,
                'client_info' => null,
                'protocol_version' => null,
                'message_queue' => [],
                'log_level' => null,
            ],
            $attributes
        );
        unset($this->data['id']);
    }

    public function queueMessage(string $rawFramedMessage): void
    {
        $this->data['message_queue'][] = $rawFramedMessage;
    }

    public function dequeueMessages(): array
    {
        $messages = $this->data['message_queue'] ?? [];
        $this->data['message_queue'] = [];
        return $messages;
    }

    public function hasQueuedMessages(): bool
    {
        return !empty($this->data['message_queue']);
    }

    public function jsonSerialize(): array
    {
        return $this->all();
    }
}
