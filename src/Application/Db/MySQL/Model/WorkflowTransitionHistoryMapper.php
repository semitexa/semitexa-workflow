<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

#[AsMapper(resourceModel: WorkflowTransitionHistoryResourceModel::class, domainModel: WorkflowTransitionHistory::class)]
final class WorkflowTransitionHistoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WorkflowTransitionHistoryResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        $history = new WorkflowTransitionHistory();
        $history->id = $resourceModel->id;
        $history->workflowInstanceId = $resourceModel->workflowInstanceId;
        $history->transitionKey = $resourceModel->transitionKey;
        $history->fromState = $resourceModel->fromState;
        $history->toState = $resourceModel->toState;
        $history->triggerType = $resourceModel->triggerType;
        $history->triggeredByType = $resourceModel->triggeredByType;
        $history->triggeredById = $resourceModel->triggeredById;
        $history->attempt = $resourceModel->attempt;
        $history->result = $resourceModel->result;
        $history->guardFailuresJson = $resourceModel->guardFailuresJson;
        $history->sideEffectFailuresJson = $resourceModel->sideEffectFailuresJson;
        $history->metadataJson = $resourceModel->metadataJson;
        $history->createdAt = $resourceModel->createdAt;

        return $history;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof WorkflowTransitionHistory || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WorkflowTransitionHistoryResourceModel(
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
