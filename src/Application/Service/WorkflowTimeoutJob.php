<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface;
use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Enum\TriggerType;
use Semitexa\Workflow\Domain\Enum\WorkflowStatus;

/**
 * Scheduler job that fires a timeout-triggered transition on a workflow instance.
 *
 * Dispatched by WorkflowEngine when a transition has a TimeoutPolicy.
 * The job is idempotent: if the instance has already moved past the
 * expected state, the transition will be rejected as invalid and
 * the job completes without error.
 *
 * A service: the scheduler builds the job from its class name through the
 * container, which builds registered services only.
 */
#[AsService]
final class WorkflowTimeoutJob implements ScheduledJobInterface
{
    #[InjectAsReadonly]
    protected WorkflowEngineInterface $engine;

    public function handle(ScheduledJobContext $context): void
    {
        if (!isset($this->engine)) {
            return;
        }

        $workflowKey   = $context->payload['workflowKey'] ?? '';
        $instanceId    = $context->payload['instanceId'] ?? '';
        $transitionKey = $context->payload['transitionKey'] ?? '';

        if ($workflowKey === '' || $instanceId === '' || $transitionKey === '') {
            return;
        }

        $instance = $this->engine->get($instanceId);
        if ($instance === null) {
            // Instance was deleted — nothing to do.
            return;
        }

        // If the instance is already in a terminal state, skip.
        if (WorkflowStatus::from($instance->getStatus())->isTerminal()) {
            return;
        }

        // A timer belongs to the visit that scheduled it. Leaving the state and
        // coming back started a new visit with its own timer; this older one
        // would have ended the new visit early.
        $stateVersion = $context->payload['stateVersion'] ?? null;
        if (is_int($stateVersion) && $instance->getVersion() !== $stateVersion) {
            return;
        }

        $this->engine->apply(new ApplyTransitionCommand(
            workflowKey:    $workflowKey,
            instanceId:     $instanceId,
            transitionKey:  $transitionKey,
            triggerType:    TriggerType::Scheduled,
            triggeredByType: 'scheduler',
            triggeredById:  $context->runId,
            context:        ['schedulerRunId' => $context->runId],
            // Checked again where it is applied: between the check above and
            // the engine's own read, the instance could leave and come back.
            expectedVersion: is_int($stateVersion) ? $stateVersion : null,
        ));
    }
}
