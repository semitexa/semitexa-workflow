<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Contract;

use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

interface WorkflowTransitionHistoryRepositoryInterface
{
    public function save(WorkflowTransitionHistory $history): void;

    /**
     * @return list<WorkflowTransitionHistory>
     */
    public function findByInstanceId(string $instanceId, int $limit = 100): array;

    /**
     * Count transition attempts for a specific transition key on an instance.
     */
    public function countAttempts(string $instanceId, string $transitionKey): int;
}
