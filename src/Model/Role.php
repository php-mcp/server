<?php

declare(strict_types=1);

namespace PhpMcp\Server\Model;

/**
 * The sender or recipient of messages and data in a conversation.
 */
enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
