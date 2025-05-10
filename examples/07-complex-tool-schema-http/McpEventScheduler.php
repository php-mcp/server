<?php

namespace Mcp\ComplexSchemaHttpExample;

use Mcp\ComplexSchemaHttpExample\Model\EventPriority;
use Mcp\ComplexSchemaHttpExample\Model\EventType;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

class McpEventScheduler
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Schedules a new event.
     * The inputSchema for this tool will reflect all parameter types and defaults.
     *
     * @param  string  $title  The title of the event.
     * @param  string  $date  The date of the event (YYYY-MM-DD).
     * @param  EventType  $type  The type of event.
     * @param  string|null  $time  The time of the event (HH:MM), optional.
     * @param  EventPriority  $priority  The priority of the event. Defaults to Normal.
     * @param  string[]|null  $attendees  An optional list of attendee email addresses.
     * @param  bool  $sendInvites  Send calendar invites to attendees? Defaults to true if attendees are provided.
     * @return array Confirmation of the scheduled event.
     */
    #[McpTool(name: 'schedule_event')]
    public function scheduleEvent(
        string $title,
        string $date,
        EventType $type,
        ?string $time = null, // Optional, nullable
        EventPriority $priority = EventPriority::Normal, // Optional with enum default
        ?array $attendees = null, // Optional array of strings, nullable
        bool $sendInvites = true   // Optional with default
    ): array {
        $this->logger->info("Tool 'schedule_event' called", compact('title', 'date', 'type', 'time', 'priority', 'attendees', 'sendInvites'));

        // Simulate scheduling logic
        $eventDetails = [
            'title' => $title,
            'date' => $date,
            'type' => $type->value, // Use enum value
            'time' => $time ?? 'All day',
            'priority' => $priority->name, // Use enum name
            'attendees' => $attendees ?? [],
            'invites_will_be_sent' => ($attendees && $sendInvites),
        ];

        // In a real app, this would interact with a calendar service
        $this->logger->info('Event scheduled', ['details' => $eventDetails]);

        return [
            'success' => true,
            'message' => "Event '{$title}' scheduled successfully for {$date}.",
            'event_details' => $eventDetails,
        ];
    }
}
