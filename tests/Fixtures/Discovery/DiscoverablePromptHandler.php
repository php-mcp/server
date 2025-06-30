<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\CompletionProvider;
use PhpMcp\Server\Tests\Fixtures\General\CompletionProviderFixture;

class DiscoverablePromptHandler
{
    /**
     * Generates a creative story prompt.
     * @param string $genre The genre of the story.
     * @param int $lengthWords Approximate length in words.
     * @return array The prompt messages.
     */
    #[McpPrompt(name: "creative_story_prompt")]
    public function generateStoryPrompt(
        #[CompletionProvider(provider: CompletionProviderFixture::class)]
        string $genre,
        int $lengthWords = 200
    ): array {
        return [
            ["role" => "user", "content" => "Write a {$genre} story about a lost robot, approximately {$lengthWords} words long."]
        ];
    }

    #[McpPrompt]
    public function simpleQuestionPrompt(string $question): array
    {
        return [
            ["role" => "user", "content" => $question],
            ["role" => "assistant", "content" => "I will try to answer that."]
        ];
    }
}
