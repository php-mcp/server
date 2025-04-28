<?php

namespace Test;

use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Attributes\McpTool;

/**
 * A sample class containing methods decorated with MCP attributes for testing discovery.
 */
class SampleMcpElements
{
    /**
     * A sample tool method.
     *
     * @param  string  $name  The name to greet.
     * @param  int|null  $count  The number of times to greet.
     * @return string The greeting message.
     */
    #[McpTool(name: 'greet_user', description: 'Generates a greeting for a user.')]
    public function simpleTool(string $name, ?int $count = 1): string
    {
        if ($count === null) {
            $count = 1;
        }

        return implode(' ', array_fill(0, $count, "Hello, {$name}!"));
    }

    #[McpTool(description: 'Another tool with no explicit name.')]
    public function anotherTool(
        string $input,
        int $count = 1
    ): array {
        return ['processed' => $input, 'count' => $count];
    }

    /**
     * Generates a simple story prompt.
     *
     * @param  string  $subject  The main subject of the story.
     * @param  string  $genre  The genre (e.g., fantasy, sci-fi).
     */
    #[McpPrompt(name: 'create_story', description: 'Creates a short story premise.')]
    public function storyPrompt(string $subject, string $genre = 'fantasy'): array
    {
        // In a real scenario, this would return the prompt string
        return [
            [
                'role' => 'user',
                'content' => "Write a short {$genre} story about {$subject}.",
            ],
        ];
    }

    #[McpPrompt]
    public function simplePrompt(): array
    {
        return [
            [
                'role' => 'user',
                'content' => 'This is a simple prompt with no arguments.',
            ],
        ];
    }

    #[McpResource(uri: 'config://app/name', name: 'app_name', description: 'The application name.', mimeType: 'text/plain')]
    public function getAppName(): string
    {
        // In a real scenario, this would fetch the config value
        return 'My MCP App';
    }

    #[McpResource(uri: 'file://data/users.csv', name: 'users_csv', mimeType: 'text/csv')]
    public function getUserData(): string
    {
        // In a real scenario, this would return file content
        return "id,name\n1,Alice\n2,Bob";
    }

    #[McpResourceTemplate(uriTemplate: 'user://{userId}/profile', name: 'user_profile', mimeType: 'application/json')]
    public function getUserProfileTemplate(string $userId): array
    {
        // In a real scenario, this would fetch user data based on userId
        return ['id' => $userId, 'name' => 'User '.$userId, 'email' => $userId.'@example.com'];
    }
}
