<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

final class WorkflowInstance
{
    public string $id = '';
    public string $workflowKey = '';
    public string $subjectType = '';
    public string $subjectId = '';
    public ?string $tenantId = null;
    public string $currentState = '';
    public string $status = 'active';
    public int $version = 0;
    public ?string $activeTransitionKey = null;
    public ?string $lastErrorCode = null;
    public ?string $lastErrorMessage = null;
    public ?\DateTimeImmutable $waitingUntil = null;
    public bool $awaitingManualAction = false;
    public ?string $payloadJson = null;
    public ?string $contextJson = null;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
    public ?\DateTimeImmutable $completedAt = null;
}
