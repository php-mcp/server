<?php

declare(strict_types=1);

namespace PhpMcp\Server\Model;

/**
 * Optional annotations for the client. The client can use annotations to inform how objects are used or displayed
 */
class Annotations
{
    /**
     * @param  Role[]|string[]|null  $audience  Describes who the intended customer of this object or data is.
     * @param  ?float  $priority  Describes how important this data is for operating the server. A value of 1 means "most important," and indicates that the data is effectively required, while 0 means "least important," and indicates that the data is entirely optional.
     */
    public function __construct(
        public readonly ?array $audience = null,
        public readonly ?float $priority = null,
    ) {
        if ($this->priority !== null && ($this->priority < 0 || $this->priority > 1)) {
            throw new \InvalidArgumentException('Priority must be between 0 and 1.');
        }
    }

    public static function default(): self
    {
        return new self(null, null);
    }

    public static function fromArray(array $data): self
    {
        return new self($data['audience'] ?? null, $data['priority'] ?? null);
    }

    public function toArray(): array
    {
        $result = [];

        if ($this->audience !== null) {
            $audience = [];

            foreach ($this->audience as $role) {
                $audience[] = $role instanceof Role ? $role->value : $role;
            }

            $result['audience'] = $audience;
        }
        if ($this->priority !== null) {
            $result['priority'] = $this->priority;
        }

        return $result;
    }
}
