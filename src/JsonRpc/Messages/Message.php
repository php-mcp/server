<?php

declare(strict_types=1);

namespace PhpMcp\Server\JsonRpc\Messages;

use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\JsonRpc\Contracts\MessageInterface;

class Message implements MessageInterface
{
    public function getId(): string|int|null
    {
        return null;
    }

    public function toArray(): array
    {
        return [];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public static function parseRequest(string $message): Request|Notification|BatchRequest
    {
        $messageData = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

        $isBatch = array_is_list($messageData) && count($messageData) > 0 && is_array($messageData[0] ?? null);

        if ($isBatch) {
            return BatchRequest::fromArray($messageData);
        } elseif (isset($messageData['method'])) {
            if (isset($messageData['id']) && $messageData['id'] !== null) {
                return Request::fromArray($messageData);
            } else {
                return Notification::fromArray($messageData);
            }
        }

        throw new McpServerException('Invalid JSON-RPC message');
    }

    public static function parseResponse(string $message): Response|Error|BatchResponse
    {
        $messageData = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

        $isBatch = array_is_list($messageData) && count($messageData) > 0 && is_array($messageData[0] ?? null);

        if ($isBatch) {
            return BatchResponse::fromArray($messageData);
        } elseif (isset($messageData['id']) && $messageData['id'] !== null) {
            return Response::fromArray($messageData);
        } else {
            return Error::fromArray($messageData);
        }
    }
}
