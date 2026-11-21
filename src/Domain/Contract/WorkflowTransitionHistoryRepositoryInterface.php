<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Contract;

use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

interface WorkflowTransitionHistoryRepositoryInterface
{
    /**
     * @param WorkflowTransitionHistory $entity
     */
    public function save(object $entity): void;

    /**
     * Reads the history of any tenant's instance: the instance id alone
     * decides. The engine reaches it only after its own tenant check; a
     * caller outside the engine must first check the instance is the current
     * tenant's ({@see \Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface::get()}).
     *
     * @return list<WorkflowTransitionHistory>
     */
    public function findByInstanceId(string $instanceId, int $limit = 100): array;

    /**
     * Count transition attempts for a specific transition key on an instance.
     * Reads the history of any tenant's instance: the instance id alone
     * decides. The engine reaches it only after its own tenant check; a
     * caller outside the engine must first check the instance is the current
     * tenant's ({@see \Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface::get()}).
     */
    public function countAttempts(string $instanceId, string $transitionKey): int;
}
