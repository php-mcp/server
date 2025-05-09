<?php

namespace Mcp\DependenciesStdioExample\Services;

use Psr\Log\LoggerInterface;

// --- Mock Services ---

interface TaskRepositoryInterface
{
    public function addTask(string $userId, string $description): array;

    public function getTasksForUser(string $userId): array;

    public function getAllTasks(): array;

    public function completeTask(int $taskId): bool;
}

class InMemoryTaskRepository implements TaskRepositoryInterface
{
    private array $tasks = [];

    private int $nextTaskId = 1;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        // Add some initial tasks
        $this->addTask('user1', 'Buy groceries');
        $this->addTask('user1', 'Write MCP example');
        $this->addTask('user2', 'Review PR');
    }

    public function addTask(string $userId, string $description): array
    {
        $task = [
            'id' => $this->nextTaskId++,
            'userId' => $userId,
            'description' => $description,
            'completed' => false,
            'createdAt' => date('c'),
        ];
        $this->tasks[$task['id']] = $task;
        $this->logger->info('Task added', ['id' => $task['id'], 'user' => $userId]);

        return $task;
    }

    public function getTasksForUser(string $userId): array
    {
        return array_values(array_filter($this->tasks, fn ($task) => $task['userId'] === $userId && ! $task['completed']));
    }

    public function getAllTasks(): array
    {
        return array_values($this->tasks);
    }

    public function completeTask(int $taskId): bool
    {
        if (isset($this->tasks[$taskId])) {
            $this->tasks[$taskId]['completed'] = true;
            $this->logger->info('Task completed', ['id' => $taskId]);

            return true;
        }

        return false;
    }
}

interface StatsServiceInterface
{
    public function getSystemStats(): array;
}

class SystemStatsService implements StatsServiceInterface
{
    private TaskRepositoryInterface $taskRepository;

    public function __construct(TaskRepositoryInterface $taskRepository)
    {
        $this->taskRepository = $taskRepository;
    }

    public function getSystemStats(): array
    {
        $allTasks = $this->taskRepository->getAllTasks();
        $completed = count(array_filter($allTasks, fn ($task) => $task['completed']));
        $pending = count($allTasks) - $completed;

        return [
            'total_tasks' => count($allTasks),
            'completed_tasks' => $completed,
            'pending_tasks' => $pending,
            'server_uptime_seconds' => time() - $_SERVER['REQUEST_TIME_FLOAT'], // Approx uptime for CLI script
        ];
    }
}
