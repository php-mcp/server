<?php

declare(strict_types=1);

namespace PhpMcp\Server\Model;

/**
 * Additional properties describing a Tool to clients.
 *
 * NOTE: all properties in ToolAnnotations are **hints**.
 * They are not guaranteed to provide a faithful description of
 * tool behavior (including descriptive properties like `title`).
 *
 * Clients should never make tool use decisions based on ToolAnnotations
 * received from untrusted servers.
 */
class ToolAnnotations
{
    /**
     * @param  ?string  $title  A human-readable title for the tool.
     * @param  ?bool  $readOnlyHint  If true, the tool does not modify its environment.
     * @param  ?bool  $destructiveHint  If true, the tool may perform destructive updates to its environment. If false, the tool performs only additive updates.
     * @param  ?bool  $idempotentHint  If true, calling the tool repeatedly with the same arguments will have no additional effect on the its environment. (This property is meaningful only when `readOnlyHint == false`)
     * @param  ?bool  $openWorldHint  If true, this tool may interact with an "open world" of external entities. If false, the tool's domain of interaction is closed. For example, the world of a web search tool is open, whereas that of a memory tool is not.
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?bool $readOnlyHint = null,
        public readonly ?bool $destructiveHint = null,
        public readonly ?bool $idempotentHint = null,
        public readonly ?bool $openWorldHint = null,
    ) {}

    public static function default(): self
    {
        return new self(
            null,
            false,
            true,
            false,
            true,
        );
    }

    public function toArray(): array
    {
        $result = [];
        if ($this->title !== null) {
            $result['title'] = $this->title;
        }
        if ($this->readOnlyHint !== null) {
            $result['readOnlyHint'] = $this->readOnlyHint;
        }
        if ($this->destructiveHint !== null) {
            $result['destructiveHint'] = $this->destructiveHint;
        }
        if ($this->idempotentHint !== null) {
            $result['idempotentHint'] = $this->idempotentHint;
        }
        if ($this->openWorldHint !== null) {
            $result['openWorldHint'] = $this->openWorldHint;
        }

        return $result;
    }
}
