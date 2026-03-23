<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Payload\Event;

final class WorkflowManualActionRequired
{
    private string $instanceId = '';
    private string $workflowKey = '';
    private string $subjectType = '';
    private string $subjectId = '';
    private string $currentState = '';
    private string $pendingTransitionKey = '';
    private ?string $tenantId = null;

    public function getInstanceId(): string { return $this->instanceId; }
    public function setInstanceId(string $instanceId): void { $this->instanceId = $instanceId; }

    public function getWorkflowKey(): string { return $this->workflowKey; }
    public function setWorkflowKey(string $workflowKey): void { $this->workflowKey = $workflowKey; }

    public function getSubjectType(): string { return $this->subjectType; }
    public function setSubjectType(string $subjectType): void { $this->subjectType = $subjectType; }

    public function getSubjectId(): string { return $this->subjectId; }
    public function setSubjectId(string $subjectId): void { $this->subjectId = $subjectId; }

    public function getCurrentState(): string { return $this->currentState; }
    public function setCurrentState(string $currentState): void { $this->currentState = $currentState; }

    public function getPendingTransitionKey(): string { return $this->pendingTransitionKey; }
    public function setPendingTransitionKey(string $pendingTransitionKey): void { $this->pendingTransitionKey = $pendingTransitionKey; }

    public function getTenantId(): ?string { return $this->tenantId; }
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }
}
