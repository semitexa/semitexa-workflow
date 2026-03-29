<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[AsMapper(tableModel: WorkflowInstanceTableModel::class, domainModel: WorkflowInstance::class)]
final class WorkflowInstanceMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof WorkflowInstanceTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        $instance = new WorkflowInstance();
        $instance->id = $tableModel->id;
        $instance->workflowKey = $tableModel->workflowKey;
        $instance->subjectType = $tableModel->subjectType;
        $instance->subjectId = $tableModel->subjectId;
        $instance->tenantId = $tableModel->tenantId;
        $instance->currentState = $tableModel->currentState;
        $instance->status = $tableModel->status;
        $instance->version = $tableModel->version;
        $instance->activeTransitionKey = $tableModel->activeTransitionKey;
        $instance->lastErrorCode = $tableModel->lastErrorCode;
        $instance->lastErrorMessage = $tableModel->lastErrorMessage;
        $instance->waitingUntil = $tableModel->waitingUntil;
        $instance->awaitingManualAction = $tableModel->awaitingManualAction;
        $instance->payloadJson = $tableModel->payloadJson;
        $instance->contextJson = $tableModel->contextJson;
        $instance->createdAt = $tableModel->createdAt;
        $instance->updatedAt = $tableModel->updatedAt;
        $instance->completedAt = $tableModel->completedAt;

        return $instance;
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof WorkflowInstance || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WorkflowInstanceTableModel(
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
