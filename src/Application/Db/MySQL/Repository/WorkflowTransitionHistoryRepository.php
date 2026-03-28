<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Workflow\Application\Db\MySQL\Model\WorkflowTransitionHistoryResource;
use Semitexa\Workflow\Contract\WorkflowTransitionHistoryRepositoryInterface;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

#[SatisfiesRepositoryContract(of: WorkflowTransitionHistoryRepositoryInterface::class)]
class WorkflowTransitionHistoryRepository extends AbstractRepository implements WorkflowTransitionHistoryRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return WorkflowTransitionHistoryResource::class;
    }

    public function save(object $history): void
    {
        $resource = WorkflowTransitionHistoryResource::fromDomain($history);
        parent::save($resource);
        $history->id = $resource->id;
    }

    public function findByInstanceId(string $instanceId, int $limit = 100): array
    {
        $binId = Uuid7::toBytes($instanceId);
        $result = $this->getAdapter()->execute(
            'SELECT * FROM workflow_transition_history WHERE workflow_instance_id = :id ORDER BY created_at ASC LIMIT :limit',
            ['id' => $binId, 'limit' => $limit],
        );
        return array_map(fn(array $row) => $this->hydrateHistory($row), $result->rows);
    }

    public function countAttempts(string $instanceId, string $transitionKey): int
    {
        $binId = Uuid7::toBytes($instanceId);
        $result = $this->getAdapter()->execute(
            'SELECT COUNT(*) as cnt FROM workflow_transition_history WHERE workflow_instance_id = :id AND transition_key = :tk',
            ['id' => $binId, 'tk' => $transitionKey],
        );
        return (int) ($result->rows[0]['cnt'] ?? 0);
    }

    private function hydrateHistory(array $row): WorkflowTransitionHistory
    {
        $h = new WorkflowTransitionHistory();
        $h->id                     = isset($row['id']) ? Uuid7::fromBytes($row['id']) : '';
        $h->workflowInstanceId     = isset($row['workflow_instance_id']) ? Uuid7::fromBytes($row['workflow_instance_id']) : '';
        $h->transitionKey          = $row['transition_key'] ?? '';
        $h->fromState              = $row['from_state'] ?? '';
        $h->toState                = $row['to_state'] ?? null;
        $h->triggerType            = $row['trigger_type'] ?? 'manual';
        $h->triggeredByType        = $row['triggered_by_type'] ?? null;
        $h->triggeredById          = $row['triggered_by_id'] ?? null;
        $h->attempt                = (int) ($row['attempt'] ?? 1);
        $h->result                 = $row['result'] ?? 'applied';
        $h->guardFailuresJson      = $row['guard_failures_json'] ?? null;
        $h->sideEffectFailuresJson = $row['side_effect_failures_json'] ?? null;
        $h->metadataJson           = $row['metadata_json'] ?? null;
        $h->createdAt              = $this->toDatetime($row['created_at'] ?? null);
        return $h;
    }

    private function toDatetime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new \DateTimeImmutable($value);
    }
}
