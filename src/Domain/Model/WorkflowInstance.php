<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

final class WorkflowInstance
{
    private string $id = '';
    private string $workflowKey = '';
    private string $subjectType = '';
    private string $subjectId = '';
    private ?string $tenantId = null;
    private string $currentState = '';
    private string $status = 'active';
    private int $version = 0;
    private ?string $activeTransitionKey = null;
    private ?string $lastErrorCode = null;
    private ?string $lastErrorMessage = null;
    private ?\DateTimeImmutable $waitingUntil = null;
    private bool $awaitingManualAction = false;
    private ?string $payloadJson = null;
    private ?string $contextJson = null;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;
    private ?\DateTimeImmutable $completedAt = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getWorkflowKey(): string
    {
        return $this->workflowKey;
    }

    public function setWorkflowKey(string $workflowKey): void
    {
        $this->workflowKey = $workflowKey;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function setSubjectType(string $subjectType): void
    {
        $this->subjectType = $subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function setSubjectId(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getCurrentState(): string
    {
        return $this->currentState;
    }

    public function setCurrentState(string $currentState): void
    {
        $this->currentState = $currentState;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    public function getActiveTransitionKey(): ?string
    {
        return $this->activeTransitionKey;
    }

    public function setActiveTransitionKey(?string $activeTransitionKey): void
    {
        $this->activeTransitionKey = $activeTransitionKey;
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function setLastErrorCode(?string $lastErrorCode): void
    {
        $this->lastErrorCode = $lastErrorCode;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): void
    {
        $this->lastErrorMessage = $lastErrorMessage;
    }

    public function getWaitingUntil(): ?\DateTimeImmutable
    {
        return $this->waitingUntil;
    }

    public function setWaitingUntil(?\DateTimeImmutable $waitingUntil): void
    {
        $this->waitingUntil = $waitingUntil;
    }

    public function isAwaitingManualAction(): bool
    {
        return $this->awaitingManualAction;
    }

    public function setAwaitingManualAction(bool $awaitingManualAction): void
    {
        $this->awaitingManualAction = $awaitingManualAction;
    }

    public function getPayloadJson(): ?string
    {
        return $this->payloadJson;
    }

    public function setPayloadJson(?string $payloadJson): void
    {
        $this->payloadJson = $payloadJson;
    }

    public function getContextJson(): ?string
    {
        return $this->contextJson;
    }

    public function setContextJson(?string $contextJson): void
    {
        $this->contextJson = $contextJson;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): void
    {
        $this->completedAt = $completedAt;
    }
}
