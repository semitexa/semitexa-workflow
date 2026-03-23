<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Payload\Event;

final class WorkflowStarted
{
    private string $instanceId = '';
    private string $workflowKey = '';
    private string $subjectType = '';
    private string $subjectId = '';
    private ?string $tenantId = null;
    private string $initialState = '';

    public function getInstanceId(): string { return $this->instanceId; }
    public function setInstanceId(string $instanceId): void { $this->instanceId = $instanceId; }

    public function getWorkflowKey(): string { return $this->workflowKey; }
    public function setWorkflowKey(string $workflowKey): void { $this->workflowKey = $workflowKey; }

    public function getSubjectType(): string { return $this->subjectType; }
    public function setSubjectType(string $subjectType): void { $this->subjectType = $subjectType; }

    public function getSubjectId(): string { return $this->subjectId; }
    public function setSubjectId(string $subjectId): void { $this->subjectId = $subjectId; }

    public function getTenantId(): ?string { return $this->tenantId; }
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }

    public function getInitialState(): string { return $this->initialState; }
    public function setInitialState(string $initialState): void { $this->initialState = $initialState; }
}
