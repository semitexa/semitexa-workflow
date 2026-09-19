<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

final class WorkflowTransitionHistory
{
    private string $id = '';
    private string $workflowInstanceId = '';
    private string $transitionKey = '';
    private string $fromState = '';
    private ?string $toState = null;
    private string $triggerType = 'manual';
    private ?string $triggeredByType = null;
    private ?string $triggeredById = null;
    private int $attempt = 1;
    private string $result = 'applied';
    private ?string $guardFailuresJson = null;
    private ?string $sideEffectFailuresJson = null;
    private ?string $metadataJson = null;
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getWorkflowInstanceId(): string
    {
        return $this->workflowInstanceId;
    }

    public function setWorkflowInstanceId(string $workflowInstanceId): void
    {
        $this->workflowInstanceId = $workflowInstanceId;
    }

    public function getTransitionKey(): string
    {
        return $this->transitionKey;
    }

    public function setTransitionKey(string $transitionKey): void
    {
        $this->transitionKey = $transitionKey;
    }

    public function getFromState(): string
    {
        return $this->fromState;
    }

    public function setFromState(string $fromState): void
    {
        $this->fromState = $fromState;
    }

    public function getToState(): ?string
    {
        return $this->toState;
    }

    public function setToState(?string $toState): void
    {
        $this->toState = $toState;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function setTriggerType(string $triggerType): void
    {
        $this->triggerType = $triggerType;
    }

    public function getTriggeredByType(): ?string
    {
        return $this->triggeredByType;
    }

    public function setTriggeredByType(?string $triggeredByType): void
    {
        $this->triggeredByType = $triggeredByType;
    }

    public function getTriggeredById(): ?string
    {
        return $this->triggeredById;
    }

    public function setTriggeredById(?string $triggeredById): void
    {
        $this->triggeredById = $triggeredById;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): void
    {
        $this->attempt = $attempt;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): void
    {
        $this->result = $result;
    }

    public function getGuardFailuresJson(): ?string
    {
        return $this->guardFailuresJson;
    }

    public function setGuardFailuresJson(?string $guardFailuresJson): void
    {
        $this->guardFailuresJson = $guardFailuresJson;
    }

    public function getSideEffectFailuresJson(): ?string
    {
        return $this->sideEffectFailuresJson;
    }

    public function setSideEffectFailuresJson(?string $sideEffectFailuresJson): void
    {
        $this->sideEffectFailuresJson = $sideEffectFailuresJson;
    }

    public function getMetadataJson(): ?string
    {
        return $this->metadataJson;
    }

    public function setMetadataJson(?string $metadataJson): void
    {
        $this->metadataJson = $metadataJson;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
