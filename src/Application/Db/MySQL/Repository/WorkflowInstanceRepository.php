<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Workflow\Application\Db\MySQL\Model\WorkflowInstanceTableModel;
use Semitexa\Workflow\Contract\WorkflowInstanceRepositoryInterface;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[SatisfiesRepositoryContract(of: WorkflowInstanceRepositoryInterface::class)]
final class WorkflowInstanceRepository implements WorkflowInstanceRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?WorkflowInstance
    {
        /** @var WorkflowInstance|null */
        return $this->repository()->findById($id);
    }

    public function findBySubject(string $workflowKey, string $subjectType, string $subjectId): ?WorkflowInstance
    {
        /** @var WorkflowInstance|null */
        return $this->repository()->query()
            ->where(WorkflowInstanceTableModel::column('workflowKey'), Operator::Equals, $workflowKey)
            ->where(WorkflowInstanceTableModel::column('subjectType'), Operator::Equals, $subjectType)
            ->where(WorkflowInstanceTableModel::column('subjectId'), Operator::Equals, $subjectId)
            ->fetchOneAs(WorkflowInstance::class, $this->orm()->getMapperRegistry());
    }

    public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array
    {
        $result = $this->adapter()->execute(
            "SELECT * FROM workflow_instances
             WHERE status IN ('waiting', 'active')
               AND waiting_until IS NOT NULL
               AND waiting_until <= :now
             ORDER BY waiting_until ASC
             LIMIT :limit",
            ['now' => $now->format('Y-m-d H:i:s.u'), 'limit' => $limit],
        );

        return array_map(
            fn (array $row): WorkflowInstance => $this->orm()->getMapperRegistry()->mapToDomain(
                $this->orm()->getTableModelHydrator()->hydrate($row, WorkflowInstanceTableModel::class),
                WorkflowInstance::class,
            ),
            $result->rows,
        );
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof WorkflowInstance) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', WorkflowInstance::class, $entity::class));
        }

        $persisted = $entity->id === ''
            ? $this->repository()->insert($entity)
            : $this->repository()->update($entity);

        $this->copyIntoMutableDomain($persisted, $entity);
    }

    public function saveWithVersionCheck(WorkflowInstance $instance, int $expectedVersion): bool
    {
        $now = new \DateTimeImmutable();
        $result = $this->adapter()->execute(
            "UPDATE workflow_instances
             SET current_state = :current_state,
                 status = :status,
                 version = :new_version,
                 active_transition_key = :active_transition_key,
                 last_error_code = :last_error_code,
                 last_error_message = :last_error_message,
                 waiting_until = :waiting_until,
                 awaiting_manual_action = :awaiting_manual_action,
                 payload_json = :payload_json,
                 context_json = :context_json,
                 completed_at = :completed_at,
                 updated_at = :updated_at
             WHERE id = :id AND version = :expected_version",
            [
                'current_state' => $instance->currentState,
                'status' => $instance->status,
                'new_version' => $instance->version,
                'active_transition_key' => $instance->activeTransitionKey,
                'last_error_code' => $instance->lastErrorCode,
                'last_error_message' => $instance->lastErrorMessage,
                'waiting_until' => $instance->waitingUntil?->format('Y-m-d H:i:s.u'),
                'awaiting_manual_action' => $instance->awaitingManualAction ? 1 : 0,
                'payload_json' => $instance->payloadJson,
                'context_json' => $instance->contextJson,
                'completed_at' => $instance->completedAt?->format('Y-m-d H:i:s.u'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
                'id' => Uuid7::toBytes($instance->id),
                'expected_version' => $expectedVersion,
            ],
        );

        if ($result->rowCount > 0) {
            $instance->updatedAt = $now;
            return true;
        }

        return false;
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WorkflowInstanceTableModel::class,
            WorkflowInstance::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }

    private function copyIntoMutableDomain(object $source, WorkflowInstance $target): void
    {
        $source instanceof WorkflowInstance || throw new \InvalidArgumentException('Unexpected persisted domain model.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
