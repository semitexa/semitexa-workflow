<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Workflow\Application\Db\MySQL\Model\WorkflowInstanceResource;
use Semitexa\Workflow\Contract\WorkflowInstanceRepositoryInterface;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[SatisfiesRepositoryContract(of: WorkflowInstanceRepositoryInterface::class)]
class WorkflowInstanceRepository extends AbstractRepository implements WorkflowInstanceRepositoryInterface
{
    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        ?\Semitexa\Orm\Hydration\StreamingHydrator $hydrator = null,
    ) {
        parent::__construct($db, $hydrator);
    }

    protected function getResourceClass(): string
    {
        return WorkflowInstanceResource::class;
    }

    public function findById(int|string $id): ?WorkflowInstance
    {
        if (!is_string($id)) {
            throw new \InvalidArgumentException(sprintf(
                'WorkflowInstanceRepository::findById expects a string UUID, %s given.',
                gettype($id),
            ));
        }

        $binId = Uuid7::toBytes($id);
        $result = $this->db->execute(
            'SELECT * FROM workflow_instances WHERE id = :id LIMIT 1',
            ['id' => $binId],
        );
        $row = $result->rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        return $this->hydrateInstance($row);
    }

    public function findBySubject(string $workflowKey, string $subjectType, string $subjectId): ?WorkflowInstance
    {
        $result = $this->db->execute(
            'SELECT * FROM workflow_instances WHERE workflow_key = :wk AND subject_type = :st AND subject_id = :si LIMIT 1',
            ['wk' => $workflowKey, 'st' => $subjectType, 'si' => $subjectId],
        );
        $row = $result->rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        return $this->hydrateInstance($row);
    }

    public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array
    {
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $result = $this->db->execute(
            "SELECT * FROM workflow_instances
             WHERE status IN ('waiting', 'active')
               AND waiting_until IS NOT NULL
               AND waiting_until <= :now
             ORDER BY waiting_until ASC
             LIMIT :limit",
            ['now' => $nowStr, 'limit' => $limit],
        );
        return array_map(fn(array $row) => $this->hydrateInstance($row), $result->rows);
    }

    public function save(object $instance): void
    {
        if (!$instance instanceof WorkflowInstance) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %s, got %s.',
                WorkflowInstance::class,
                $instance::class,
            ));
        }

        $resource = WorkflowInstanceResource::fromDomain($instance);
        parent::save($resource);
        $instance->id = $resource->id;
    }

    public function saveWithVersionCheck(WorkflowInstance $instance, int $expectedVersion): bool
    {
        $now = new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $binId = Uuid7::toBytes($instance->id);

        $result = $this->db->execute(
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
                'current_state'          => $instance->currentState,
                'status'                 => $instance->status,
                'new_version'            => $instance->version,
                'active_transition_key'  => $instance->activeTransitionKey,
                'last_error_code'        => $instance->lastErrorCode,
                'last_error_message'     => $instance->lastErrorMessage,
                'waiting_until'          => $instance->waitingUntil?->format('Y-m-d H:i:s.u'),
                'awaiting_manual_action' => $instance->awaitingManualAction ? 1 : 0,
                'payload_json'           => $instance->payloadJson,
                'context_json'           => $instance->contextJson,
                'completed_at'           => $instance->completedAt?->format('Y-m-d H:i:s.u'),
                'updated_at'             => $nowStr,
                'id'                     => $binId,
                'expected_version'       => $expectedVersion,
            ],
        );

        if ($result->rowCount > 0) {
            $instance->updatedAt = $now;
            return true;
        }
        return false;
    }

    private function hydrateInstance(array $row): WorkflowInstance
    {
        $instance = new WorkflowInstance();
        $instance->id                  = isset($row['id']) ? Uuid7::fromBytes($row['id']) : '';
        $instance->workflowKey         = $row['workflow_key'] ?? '';
        $instance->subjectType         = $row['subject_type'] ?? '';
        $instance->subjectId           = $row['subject_id'] ?? '';
        $instance->tenantId            = $row['tenant_id'] ?? null;
        $instance->currentState        = $row['current_state'] ?? '';
        $instance->status              = $row['status'] ?? 'active';
        $instance->version             = (int) ($row['version'] ?? 0);
        $instance->activeTransitionKey = $row['active_transition_key'] ?? null;
        $instance->lastErrorCode       = $row['last_error_code'] ?? null;
        $instance->lastErrorMessage    = $row['last_error_message'] ?? null;
        $instance->waitingUntil        = $this->toDatetime($row['waiting_until'] ?? null);
        $instance->awaitingManualAction = (bool) ($row['awaiting_manual_action'] ?? false);
        $instance->payloadJson         = $row['payload_json'] ?? null;
        $instance->contextJson         = $row['context_json'] ?? null;
        $instance->createdAt           = $this->toDatetime($row['created_at'] ?? null);
        $instance->updatedAt           = $this->toDatetime($row['updated_at'] ?? null);
        $instance->completedAt         = $this->toDatetime($row['completed_at'] ?? null);
        return $instance;
    }

    private function toDatetime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new \DateTimeImmutable($value);
    }
}
