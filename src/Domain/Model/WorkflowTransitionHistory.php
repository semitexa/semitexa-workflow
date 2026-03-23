<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

final class WorkflowTransitionHistory
{
    public string $id = '';
    public string $workflowInstanceId = '';
    public string $transitionKey = '';
    public string $fromState = '';
    public ?string $toState = null;
    public string $triggerType = 'manual';
    public ?string $triggeredByType = null;
    public ?string $triggeredById = null;
    public int $attempt = 1;
    public string $result = 'applied';
    public ?string $guardFailuresJson = null;
    public ?string $sideEffectFailuresJson = null;
    public ?string $metadataJson = null;
    public ?\DateTimeImmutable $createdAt = null;
}
