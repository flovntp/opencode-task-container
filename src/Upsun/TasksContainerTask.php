<?php

namespace App\Upsun;

use Psr\Http\Client\ClientExceptionInterface;
use Upsun\Api\ApiException;
use Upsun\Api\TaskApi;
use Upsun\Core\Tasks\TaskBase;
use Upsun\Model\AcceptedResponse;
use Upsun\Model\Task;
use Upsun\Model\TaskCollection;
use Upsun\Model\TaskTriggerInput;
use Upsun\UpsunClient;

/**
 * High-level wrapper around the low-level TaskApi.
 *
 * Usage (via UpsunClientFactory):
 *   $client->tasksContainer->run($projectId, $environmentId, $taskId, $variables);
 */
final class TasksContainerTask extends TaskBase
{
    public function __construct(
        UpsunClient $client,
        private readonly TaskApi $taskApi,
    ) {
        parent::__construct($client);
    }

    /**
     * Trigger a task container run.
     *
     * @param array<string, array<string, mixed>> $variables Environment variables forwarded to the task container.
     *
     * @throws ApiException on non-2xx response
     * @throws ClientExceptionInterface
     */
    public function run(
        string $projectId,
        string $environmentId,
        string $taskId,
        array $variables = [],
    ): AcceptedResponse {
        return $this->taskApi->runTask(
            projectId: $projectId,
            environmentId: $environmentId,
            taskId: $taskId,
            taskTriggerInput: new TaskTriggerInput(variables: $variables),
        );
    }

    /**
     * Get a single task by ID.
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function get(
        string $projectId,
        string $environmentId,
        string $taskId,
    ): Task {
        return $this->taskApi->getProjectsEnvironmentsTasks(
            projectId: $projectId,
            environmentId: $environmentId,
            taskId: $taskId,
        );
    }

    /**
     * List all tasks for an environment.
     *
     * @throws ApiException
     * @throws ClientExceptionInterface
     */
    public function list(
        string $projectId,
        string $environmentId,
    ): TaskCollection {
        return $this->taskApi->listProjectsEnvironmentsTasks(
            projectId: $projectId,
            environmentId: $environmentId,
        );
    }
}
