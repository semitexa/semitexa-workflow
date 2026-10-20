<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Contract;

use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionResult;

interface WorkflowEngineInterface
{
    /**
     * Start a new workflow instance for the given subject.
     *
     * @throws \Semitexa\Workflow\Domain\Exception\WorkflowDefinitionNotFoundException
     * @throws \Semitexa\Workflow\Domain\Exception\WorkflowAlreadyExistsException
     */
    public function start(StartWorkflowCommand $command): WorkflowInstance;

    /**
     * Attempt to apply a transition to an existing workflow instance.
     *
     * Returns a result indicating whether the transition was applied or rejected.
     * Does not throw on guard failures or invalid transitions — those are represented
     * as rejected results with structured failure information.
     *
     * @throws \Semitexa\Workflow\Domain\Exception\WorkflowDefinitionNotFoundException
     * @throws \Semitexa\Workflow\Domain\Exception\WorkflowInstanceNotFoundException
     */
    public function apply(ApplyTransitionCommand $command): WorkflowTransitionResult;

    /**
     * Run one side effect of an applied transition again, as its RetryPolicy
     * scheduled it: on failure the next attempt is scheduled with the policy's
     * backoff, and after maxAttempts attempts in all the engine gives up and
     * logs an error. A class the transition does not declare is not run.
     * The side effect sees the instance as it is now, and the context as it
     * was stored: JSON, so a date or an enum comes back as an array or a value.
     *
     * @param array<string, mixed> $context the command context the transition ran with
     */
    public function retrySideEffect(string $workflowKey, string $instanceId, string $transitionKey, string $sideEffectClass, int $attempt, array $context = []): void;

    /**
     * Load a workflow instance by ID.
     */
    public function get(string $instanceId): ?WorkflowInstance;

    /**
     * Find a workflow instance by subject reference and workflow key.
     */
    public function findBySubject(string $workflowKey, WorkflowSubjectReferenceInterface $subject): ?WorkflowInstance;
}
