<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

#[AsMapper(tableModel: WorkflowTransitionHistoryTableModel::class, domainModel: WorkflowTransitionHistory::class)]
final class WorkflowTransitionHistoryMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof WorkflowTransitionHistoryTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        $history = new WorkflowTransitionHistory();
        $history->id = $tableModel->id;
        $history->workflowInstanceId = $tableModel->workflowInstanceId;
        $history->transitionKey = $tableModel->transitionKey;
        $history->fromState = $tableModel->fromState;
        $history->toState = $tableModel->toState;
        $history->triggerType = $tableModel->triggerType;
        $history->triggeredByType = $tableModel->triggeredByType;
        $history->triggeredById = $tableModel->triggeredById;
        $history->attempt = $tableModel->attempt;
        $history->result = $tableModel->result;
        $history->guardFailuresJson = $tableModel->guardFailuresJson;
        $history->sideEffectFailuresJson = $tableModel->sideEffectFailuresJson;
        $history->metadataJson = $tableModel->metadataJson;
        $history->createdAt = $tableModel->createdAt;

        return $history;
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof WorkflowTransitionHistory || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WorkflowTransitionHistoryTableModel(
            id: $domainModel->id,
            workflowInstanceId: $domainModel->workflowInstanceId,
            transitionKey: $domainModel->transitionKey,
            fromState: $domainModel->fromState,
            toState: $domainModel->toState,
            triggerType: $domainModel->triggerType,
            triggeredByType: $domainModel->triggeredByType,
            triggeredById: $domainModel->triggeredById,
            attempt: $domainModel->attempt,
            result: $domainModel->result,
            guardFailuresJson: $domainModel->guardFailuresJson,
            sideEffectFailuresJson: $domainModel->sideEffectFailuresJson,
            metadataJson: $domainModel->metadataJson,
            createdAt: $domainModel->createdAt,
        );
    }
}
