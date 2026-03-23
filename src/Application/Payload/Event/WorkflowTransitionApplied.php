<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Payload\Event;

final class WorkflowTransitionApplied
{
    private string $instanceId = '';
    private string $workflowKey = '';
    private string $transitionKey = '';
    private string $fromState = '';
    private string $toState = '';
    private string $triggerType = '';
    private ?string $tenantId = null;

    public function getInstanceId(): string { return $this->instanceId; }
    public function setInstanceId(string $instanceId): void { $this->instanceId = $instanceId; }

    public function getWorkflowKey(): string { return $this->workflowKey; }
    public function setWorkflowKey(string $workflowKey): void { $this->workflowKey = $workflowKey; }

    public function getTransitionKey(): string { return $this->transitionKey; }
    public function setTransitionKey(string $transitionKey): void { $this->transitionKey = $transitionKey; }

    public function getFromState(): string { return $this->fromState; }
    public function setFromState(string $fromState): void { $this->fromState = $fromState; }

    public function getToState(): string { return $this->toState; }
    public function setToState(string $toState): void { $this->toState = $toState; }

    public function getTriggerType(): string { return $this->triggerType; }
    public function setTriggerType(string $triggerType): void { $this->triggerType = $triggerType; }

    public function getTenantId(): ?string { return $this->tenantId; }
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }
}
