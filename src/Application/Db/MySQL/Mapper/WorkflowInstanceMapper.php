<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Workflow\Application\Db\MySQL\Model\WorkflowInstanceResourceModel;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[AsMapper(resourceModel: WorkflowInstanceResourceModel::class, domainModel: WorkflowInstance::class)]
final class WorkflowInstanceMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WorkflowInstanceResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        $instance = new WorkflowInstance();
        $instance->setId($resourceModel->id);
        $instance->setWorkflowKey($resourceModel->workflowKey);
        $instance->setSubjectType($resourceModel->subjectType);
        $instance->setSubjectId($resourceModel->subjectId);
        $instance->setTenantId($resourceModel->tenantId);
        $instance->setCurrentState($resourceModel->currentState);
        $instance->setStatus($resourceModel->status);
        $instance->setVersion($resourceModel->version);
        $instance->setActiveTransitionKey($resourceModel->activeTransitionKey);
        $instance->setLastErrorCode($resourceModel->lastErrorCode);
        $instance->setLastErrorMessage($resourceModel->lastErrorMessage);
        $instance->setWaitingUntil($resourceModel->waitingUntil);
        $instance->setAwaitingManualAction($resourceModel->awaitingManualAction);
        $instance->setPayloadJson($resourceModel->payloadJson);
        $instance->setContextJson($resourceModel->contextJson);
        $instance->setCreatedAt($resourceModel->createdAt);
        $instance->setUpdatedAt($resourceModel->updatedAt);
        $instance->setCompletedAt($resourceModel->completedAt);

        return $instance;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof WorkflowInstance || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WorkflowInstanceResourceModel(
            id: $domainModel->getId(),
            workflowKey: $domainModel->getWorkflowKey(),
            subjectType: $domainModel->getSubjectType(),
            subjectId: $domainModel->getSubjectId(),
            tenantId: $domainModel->getTenantId(),
            currentState: $domainModel->getCurrentState(),
            status: $domainModel->getStatus(),
            version: $domainModel->getVersion(),
            activeTransitionKey: $domainModel->getActiveTransitionKey(),
            lastErrorCode: $domainModel->getLastErrorCode(),
            lastErrorMessage: $domainModel->getLastErrorMessage(),
            waitingUntil: $domainModel->getWaitingUntil(),
            awaitingManualAction: $domainModel->isAwaitingManualAction(),
            payloadJson: $domainModel->getPayloadJson(),
            contextJson: $domainModel->getContextJson(),
            createdAt: $domainModel->getCreatedAt(),
            updatedAt: $domainModel->getUpdatedAt(),
            completedAt: $domainModel->getCompletedAt(),
        );
    }
}
