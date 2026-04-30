<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[AsMapper(resourceModel: WorkflowInstanceResourceModel::class, domainModel: WorkflowInstance::class)]
final class WorkflowInstanceMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WorkflowInstanceResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        $instance = new WorkflowInstance();
        $instance->id = $resourceModel->id;
        $instance->workflowKey = $resourceModel->workflowKey;
        $instance->subjectType = $resourceModel->subjectType;
        $instance->subjectId = $resourceModel->subjectId;
        $instance->tenantId = $resourceModel->tenantId;
        $instance->currentState = $resourceModel->currentState;
        $instance->status = $resourceModel->status;
        $instance->version = $resourceModel->version;
        $instance->activeTransitionKey = $resourceModel->activeTransitionKey;
        $instance->lastErrorCode = $resourceModel->lastErrorCode;
        $instance->lastErrorMessage = $resourceModel->lastErrorMessage;
        $instance->waitingUntil = $resourceModel->waitingUntil;
        $instance->awaitingManualAction = $resourceModel->awaitingManualAction;
        $instance->payloadJson = $resourceModel->payloadJson;
        $instance->contextJson = $resourceModel->contextJson;
        $instance->createdAt = $resourceModel->createdAt;
        $instance->updatedAt = $resourceModel->updatedAt;
        $instance->completedAt = $resourceModel->completedAt;

        return $instance;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof WorkflowInstance || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WorkflowInstanceResourceModel(
            id: $domainModel->id,
            workflowKey: $domainModel->workflowKey,
            subjectType: $domainModel->subjectType,
            subjectId: $domainModel->subjectId,
            tenantId: $domainModel->tenantId,
            currentState: $domainModel->currentState,
            status: $domainModel->status,
            version: $domainModel->version,
            activeTransitionKey: $domainModel->activeTransitionKey,
            lastErrorCode: $domainModel->lastErrorCode,
            lastErrorMessage: $domainModel->lastErrorMessage,
            waitingUntil: $domainModel->waitingUntil,
            awaitingManualAction: $domainModel->awaitingManualAction,
            payloadJson: $domainModel->payloadJson,
            contextJson: $domainModel->contextJson,
            createdAt: $domainModel->createdAt,
            updatedAt: $domainModel->updatedAt,
            completedAt: $domainModel->completedAt,
        );
    }
}
