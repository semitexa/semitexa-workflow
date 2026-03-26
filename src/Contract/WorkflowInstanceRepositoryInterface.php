<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Contract;

use Semitexa\Workflow\Domain\Model\WorkflowInstance;

interface WorkflowInstanceRepositoryInterface
{
    public function findById(string $id): ?WorkflowInstance;

    public function findBySubject(string $workflowKey, string $subjectType, string $subjectId): ?WorkflowInstance;

    /**
     * Find instances that are in a waiting state and past their waiting_until deadline.
     * @return list<WorkflowInstance>
     */
    public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array;

    public function save(object $entity): void;

    /**
     * Attempt an optimistic-lock update: only applies if current version matches.
     * Returns true on success, false on version conflict.
     */
    public function saveWithVersionCheck(WorkflowInstance $instance, int $expectedVersion): bool;
}
