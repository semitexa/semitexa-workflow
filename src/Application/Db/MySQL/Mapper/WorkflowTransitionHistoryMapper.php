<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Workflow\Application\Db\MySQL\Model\WorkflowTransitionHistoryResourceModel;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

#[AsMapper(resourceModel: WorkflowTransitionHistoryResourceModel::class, domainModel: WorkflowTransitionHistory::class)]
final class WorkflowTransitionHistoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WorkflowTransitionHistoryResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        $history = new WorkflowTransitionHistory();
        $history->setId($resourceModel->id);
        $history->setWorkflowInstanceId($resourceModel->workflowInstanceId);
        $history->setTransitionKey($resourceModel->transitionKey);
        $history->setFromState($resourceModel->fromState);
        $history->setToState($resourceModel->toState);
        $history->setTriggerType($resourceModel->triggerType);
        $history->setTriggeredByType($resourceModel->triggeredByType);
        $history->setTriggeredById($resourceModel->triggeredById);
        $history->setAttempt($resourceModel->attempt);
        $history->setResult($resourceModel->result);
        $history->setGuardFailuresJson($resourceModel->guardFailuresJson);
        $history->setSideEffectFailuresJson($resourceModel->sideEffectFailuresJson);
        $history->setMetadataJson($resourceModel->metadataJson);
        $history->setCreatedAt($resourceModel->createdAt);

        return $history;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof WorkflowTransitionHistory || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WorkflowTransitionHistoryResourceModel(
            id: $domainModel->getId(),
            workflowInstanceId: $domainModel->getWorkflowInstanceId(),
            transitionKey: $domainModel->getTransitionKey(),
            fromState: $domainModel->getFromState(),
            toState: $domainModel->getToState(),
            triggerType: $domainModel->getTriggerType(),
            triggeredByType: $domainModel->getTriggeredByType(),
            triggeredById: $domainModel->getTriggeredById(),
            attempt: $domainModel->getAttempt(),
            result: $domainModel->getResult(),
            guardFailuresJson: $domainModel->getGuardFailuresJson(),
            sideEffectFailuresJson: $domainModel->getSideEffectFailuresJson(),
            metadataJson: $domainModel->getMetadataJson(),
            createdAt: $domainModel->getCreatedAt(),
        );
    }
}
