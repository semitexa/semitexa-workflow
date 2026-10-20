<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface;

/**
 * Scheduler job that runs one failed side effect of an applied transition
 * again, as the transition's RetryPolicy says.
 *
 * Dispatched by WorkflowEngine when a side effect of a transition with a
 * RetryPolicy fails; the engine schedules the next attempt itself, so each
 * wait follows the policy's backoff and the last attempt is maxAttempts.
 *
 * A service: the scheduler builds the job from its class name through the
 * container, which builds registered services only.
 */
#[AsService]
final class WorkflowSideEffectRetryJob implements ScheduledJobInterface
{
    #[InjectAsReadonly]
    protected WorkflowEngineInterface $engine;

    public function handle(ScheduledJobContext $context): void
    {
        if (!isset($this->engine)) {
            return;
        }

        $payload = $context->payload;
        $workflowKey   = is_string($payload['workflowKey'] ?? null) ? $payload['workflowKey'] : '';
        $instanceId    = is_string($payload['instanceId'] ?? null) ? $payload['instanceId'] : '';
        $transitionKey = is_string($payload['transitionKey'] ?? null) ? $payload['transitionKey'] : '';
        $sideEffect    = is_string($payload['sideEffect'] ?? null) ? $payload['sideEffect'] : '';
        $attempt       = is_int($payload['attempt'] ?? null) ? $payload['attempt'] : 0;
        $callContext   = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        if ($workflowKey === '' || $instanceId === '' || $transitionKey === '' || $sideEffect === '' || $attempt < 2) {
            return;
        }

        $this->engine->retrySideEffect($workflowKey, $instanceId, $transitionKey, $sideEffect, $attempt, $callContext);
    }
}
