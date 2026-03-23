<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Contract;

use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Value\WorkflowTransitionResult;

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
     * Load a workflow instance by ID.
     */
    public function get(string $instanceId): ?WorkflowInstance;

    /**
     * Find a workflow instance by subject reference and workflow key.
     */
    public function findBySubject(string $workflowKey, WorkflowSubjectReferenceInterface $subject): ?WorkflowInstance;
}
