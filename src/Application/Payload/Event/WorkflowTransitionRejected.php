<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Payload\Event;

final class WorkflowTransitionRejected
{
    private string $instanceId = '';
    private string $workflowKey = '';
    private string $transitionKey = '';
    private string $fromState = '';
    private string $rejectionReason = '';
    private ?string $failureCode = null;
    private ?string $tenantId = null;

    public function getInstanceId(): string { return $this->instanceId; }
    public function setInstanceId(string $instanceId): void { $this->instanceId = $instanceId; }

    public function getWorkflowKey(): string { return $this->workflowKey; }
    public function setWorkflowKey(string $workflowKey): void { $this->workflowKey = $workflowKey; }

    public function getTransitionKey(): string { return $this->transitionKey; }
    public function setTransitionKey(string $transitionKey): void { $this->transitionKey = $transitionKey; }

    public function getFromState(): string { return $this->fromState; }
    public function setFromState(string $fromState): void { $this->fromState = $fromState; }

    public function getRejectionReason(): string { return $this->rejectionReason; }
    public function setRejectionReason(string $rejectionReason): void { $this->rejectionReason = $rejectionReason; }

    public function getFailureCode(): ?string { return $this->failureCode; }
    public function setFailureCode(?string $failureCode): void { $this->failureCode = $failureCode; }

    public function getTenantId(): ?string { return $this->tenantId; }
    public function setTenantId(?string $tenantId): void { $this->tenantId = $tenantId; }
}
