<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Contract;

use Semitexa\Workflow\Domain\Model\WorkflowInstance;

interface WorkflowInstanceRepositoryInterface
{
    /**
     * Reads every tenant's rows: the id alone decides. The engine applies the
     * tenant check ({@see \Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface::get()});
     * a caller outside the engine must scope the result itself.
     */
    public function findById(string $id): ?WorkflowInstance;

    /** The subject's instance in its own tenant (null tenant: the instances that have none). */
    public function findBySubject(string $workflowKey, string $subjectType, string $subjectId, ?string $tenantId): ?WorkflowInstance;

    /**
     * Find instances that are in a waiting state and past their waiting_until deadline.
     * @return list<WorkflowInstance>
     */
    public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array;

    /**
     * @param WorkflowInstance $entity
     */
    public function save(object $entity): void;

    /**
     * Attempt an optimistic-lock update: only applies if current version matches.
     * Returns true on success, false on version conflict.
     */
    public function saveWithVersionCheck(WorkflowInstance $instance, int $expectedVersion): bool;
}
