<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Contract;

use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Value\GuardResult;

/**
 * Evaluates whether a transition is allowed.
 *
 * Guards must be small and composable. They return a structured result
 * rather than throwing exceptions, so failures are recorded and inspectable.
 */
interface WorkflowGuardInterface
{
    public function evaluate(
        WorkflowSubjectReferenceInterface $subject,
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        array $context,
    ): GuardResult;
}
