<?php

namespace Mcp\ManualStdioExample;

use Psr\Log\LoggerInterface;

class SimpleHandlers
{
    private LoggerInterface $logger;

    private string $appVersion = '1.0-manual';

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->logger->info('SimpleHandlers instantiated for manual registration example.');
    }

    /**
     * A manually registered tool to echo input.
     *
     * @param  string  $text  The text to echo.
     * @return string The echoed text.
     */
    public function echoText(string $text): string
    {
        $this->logger->info("Manual tool 'echo_text' called.", ['text' => $text]);

        return 'Echo: '.$text;
    }

    /**
     * A manually registered resource providing app version.
     *
     * @return string The application version.
     */
    public function getAppVersion(): string
    {
        $this->logger->info("Manual resource 'app://version' read.");

        return $this->appVersion;
    }

    /**
     * A manually registered prompt template.
     *
     * @param  string  $userName  The name of the user.
     * @return array The prompt messages.
     */
    public function greetingPrompt(string $userName): array
    {
        $this->logger->info("Manual prompt 'personalized_greeting' called.", ['userName' => $userName]);

        return [
            ['role' => 'user', 'content' => "Craft a personalized greeting for {$userName}."],
        ];
    }

    /**
     * A manually registered resource template.
     *
     * @param  string  $itemId  The ID of the item.
     * @return array Item details.
     */
    public function getItemDetails(string $itemId): array
    {
        $this->logger->info("Manual template 'item://{itemId}' resolved.", ['itemId' => $itemId]);

        return ['id' => $itemId, 'name' => "Item {$itemId}", 'description' => "Details for item {$itemId} from manual template."];
    }
}
