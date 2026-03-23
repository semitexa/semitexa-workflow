<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Payload\Event;

final class WorkflowEnteredWaitingState
{
    private string $instanceId = '';
    private string $workflowKey = '';
    private string $currentState = '';
    private ?string $waitingUntil = null;
    private ?string $tenantId = null;

    public function getInstanceId(): string { return $this->instanceId; }
    public function setInstanceId(string $instanceId): void { $this->instanceId = $instanceId; }

    public function getWorkflowKey(): string { return $this->workflowKey; }
    public function setWorkflowKey(string $workflowKey): void { $this->workflowKey = $workflowKey; }

    public function getCurrentState(): string { return $this->currentState; }
    public function setCurrentState(string $currentState): void { $this->currentState = $currentState; }

    public function getWaitingUntil(): ?string { return $this->waitingUntil; }
    public function setWaitingUntil(?string $waitingUntil): void { $this->waitingUntil = $waitingUntil; }

    public function getTenantId(): ?string { return $this->tenantId; }
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }
}
