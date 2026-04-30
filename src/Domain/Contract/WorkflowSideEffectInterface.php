<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Contract;

use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Value\SideEffectResult;

/**
 * Executes a side-effect after a successful state transition.
 *
 * Side-effects run after the transaction commits. They must be idempotent
 * because the workflow instance state has already been persisted before
 * this method is called.
 *
 * Failures are recorded but do not roll back the state change.
 * Use scheduler-dispatched jobs for slow external work.
 */
interface WorkflowSideEffectInterface
{
    public function execute(
        WorkflowSubjectReferenceInterface $subject,
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        array $context,
    ): SideEffectResult;
}
