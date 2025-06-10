<?php

declare(strict_types=1);

namespace Mcp\HttpUserProfileExample;

use PhpMcp\Server\Contracts\CompletionProviderInterface;
use PhpMcp\Server\Contracts\SessionInterface;

class UserIdCompletionProvider implements CompletionProviderInterface
{
    public function getCompletions(string $currentValue, SessionInterface $session): array
    {
        $availableUserIds = ['101', '102', '103'];
        $filteredUserIds = array_filter($availableUserIds, fn(string $userId) => str_contains($userId, $currentValue));

        return $filteredUserIds;
    }
}
