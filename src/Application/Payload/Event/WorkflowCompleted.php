<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Payload\Event;

final class WorkflowCompleted
{
    private string $instanceId = '';
    private string $workflowKey = '';
    private string $subjectType = '';
    private string $subjectId = '';
    private string $finalState = '';
    private ?string $tenantId = null;

    public function getInstanceId(): string { return $this->instanceId; }
    public function setInstanceId(string $instanceId): void { $this->instanceId = $instanceId; }

    public function getWorkflowKey(): string { return $this->workflowKey; }
    public function setWorkflowKey(string $workflowKey): void { $this->workflowKey = $workflowKey; }

    public function getSubjectType(): string { return $this->subjectType; }
    public function setSubjectType(string $subjectType): void { $this->subjectType = $subjectType; }

    public function getSubjectId(): string { return $this->subjectId; }
    public function setSubjectId(string $subjectId): void { $this->subjectId = $subjectId; }

    public function getFinalState(): string { return $this->finalState; }
    public function setFinalState(string $finalState): void { $this->finalState = $finalState; }

    public function getTenantId(): ?string { return $this->tenantId; }
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }
}
