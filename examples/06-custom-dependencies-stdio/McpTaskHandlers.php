<?php

namespace Mcp\DependenciesStdioExample;

use Mcp\DependenciesStdioExample\Services\StatsServiceInterface;
use Mcp\DependenciesStdioExample\Services\TaskRepositoryInterface;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

class McpTaskHandlers
{
    private TaskRepositoryInterface $taskRepo;

    private StatsServiceInterface $statsService;

    private LoggerInterface $logger;

    // Dependencies injected by the DI container
    public function __construct(
        TaskRepositoryInterface $taskRepo,
        StatsServiceInterface $statsService,
        LoggerInterface $logger
    ) {
        $this->taskRepo = $taskRepo;
        $this->statsService = $statsService;
        $this->logger = $logger;
        $this->logger->info('McpTaskHandlers instantiated with dependencies.');
    }

    /**
     * Adds a new task for a given user.
     *
     * @param  string  $userId  The ID of the user.
     * @param  string  $description  The task description.
     * @return array The created task details.
     */
    #[McpTool(name: 'add_task')]
    public function addTask(string $userId, string $description): array
    {
        $this->logger->info("Tool 'add_task' invoked", ['userId' => $userId]);

        return $this->taskRepo->addTask($userId, $description);
    }

    /**
     * Lists pending tasks for a specific user.
     *
     * @param  string  $userId  The ID of the user.
     * @return array A list of tasks.
     */
    #[McpTool(name: 'list_user_tasks')]
    public function listUserTasks(string $userId): array
    {
        $this->logger->info("Tool 'list_user_tasks' invoked", ['userId' => $userId]);

        return $this->taskRepo->getTasksForUser($userId);
    }

    /**
     * Marks a task as complete.
     *
     * @param  int  $taskId  The ID of the task to complete.
     * @return array Status of the operation.
     */
    #[McpTool(name: 'complete_task')]
    public function completeTask(int $taskId): array
    {
        $this->logger->info("Tool 'complete_task' invoked", ['taskId' => $taskId]);
        $success = $this->taskRepo->completeTask($taskId);

        return ['success' => $success, 'message' => $success ? "Task {$taskId} completed." : "Task {$taskId} not found."];
    }

    /**
     * Provides current system statistics.
     *
     * @return array System statistics.
     */
    #[McpResource(uri: 'stats://system/overview', name: 'system_stats', mimeType: 'application/json')]
    public function getSystemStatistics(): array
    {
        $this->logger->info("Resource 'stats://system/overview' invoked");

        return $this->statsService->getSystemStats();
    }
}
