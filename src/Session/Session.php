<?php

declare(strict_types=1);

namespace PhpMcp\Server\Session;

use PhpMcp\Server\Contracts\SessionInterface;

class Session implements SessionInterface
{
    protected string $id;

    /**
     * @var array<string, mixed> Stores all session data.
     * Keys are snake_case by convention for MCP-specific data.
     */
    protected array $data = [
        'initialized' => false,
        'client_info' => null,
        'protocol_version' => null,
        'subscriptions' => [],      // [uri => true]
        'message_queue' => [],      // string[] (raw JSON-RPC frames)
        'requested_log_level' => null,
        'last_activity_timestamp' => 0,
    ];

    public function __construct(string $sessionId)
    {
        $this->id = $sessionId;
        $this->touch();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function initialize(): void
    {
        $this->setAttribute('initialized', true);
    }

    public function isInitialized(): bool
    {
        return (bool) $this->getAttribute('initialized', false);
    }

    public function getAttribute(string $key, mixed $default = null): mixed
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

    public function setAttribute(string $key, mixed $value, bool $overwrite = true): void
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
        $this->touch();
    }

    public function hasAttribute(string $key): bool
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

    public function forgetAttribute(string $key): void
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

        $this->touch();
    }

    public function pullAttribute(string $key, mixed $default = null): mixed
    {
        $value = $this->getAttribute($key, $default);
        $this->forgetAttribute($key);
        return $value;
    }

    public function getAttributes(): array
    {
        return $this->data;
    }

    public function setAttributes(array $attributes): void
    {
        $this->data = array_merge(
            [
                'initialized' => false,
                'client_info' => null,
                'protocol_version' => null,
                'subscriptions' => [],
                'message_queue' => [],
                'requested_log_level' => null,
                'last_activity_timestamp' => 0,
            ],
            $attributes
        );
        unset($this->data['id']);

        if (!isset($attributes['last_activity_timestamp'])) {
            $this->touch();
        } else {
            $this->data['last_activity_timestamp'] = (int) $attributes['last_activity_timestamp'];
        }
    }

    public function touch(): void
    {
        $this->data['last_activity_timestamp'] = time();
    }

    public function queueMessage(string $rawFramedMessage): void
    {
        $this->data['message_queue'][] = $rawFramedMessage;
    }

    public function dequeueMessages(): array
    {
        $messages = $this->data['message_queue'] ?? [];
        $this->data['message_queue'] = [];

        if (!empty($messages)) {
            $this->touch();
        }

        return $messages;
    }

    public function hasQueuedMessages(): bool
    {
        return !empty($this->data['message_queue']);
    }

    public function jsonSerialize(): array
    {
        return $this->getAttributes();
    }
}
