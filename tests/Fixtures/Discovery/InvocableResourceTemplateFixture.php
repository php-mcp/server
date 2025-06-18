<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Server\Attributes\McpResourceTemplate;

#[McpResourceTemplate(uriTemplate: "invokable://user-profile/{userId}")]
class InvocableResourceTemplateFixture
{
    /**
     * @param string $userId
     * @return array
     */
    public function __invoke(string $userId): array
    {
        return ["id" => $userId, "email" => "user{$userId}@example-invokable.com"];
    }
}
