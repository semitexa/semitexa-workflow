<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerInterface;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

/**
 * What a transition leaves for later — its timeout, a retry of a failed side
 * effect — handed to the scheduler. Never throws: the transition is already
 * committed, and a scheduler that cannot take the job is logged instead.
 */
final class WorkflowFollowUpScheduler
{
    public function __construct(
        private readonly ?SchedulerInterface $scheduler,
        private readonly ?LoggerInterface $logger,
    ) {}

    /** The transition's timeout; whether it was scheduled. */
    public function timeout(WorkflowInstance $instance, TransitionDefinition $transition, string $appliedKey): bool
    {
        if ($transition->timeout === null || $this->scheduler === null) {
            return false;
        }
        $runAt = (new \DateTimeImmutable())->modify("+{$transition->timeout->afterSeconds} seconds");
        // Thrown, it left the instance waiting with no timer and the
        // caller with an exception for a transition that had happened.
        try {
            $this->scheduler->dispatchAt(
                jobClass: WorkflowTimeoutJob::class,
                runAt: $runAt,
                payload: [
                    'workflowKey'   => $instance->getWorkflowKey(),
                    'instanceId'    => $instance->getId(),
                    'transitionKey' => $transition->timeout->transitionKey,
                    'stateVersion'  => $instance->getVersion(), // a later visit has its own timer
                ],
                pool: $transition->timeout->pool,
                tenantId: $instance->getTenantId(),
                lockKey: "workflow_timeout_{$instance->getId()}_{$transition->timeout->transitionKey}",
                // One failed attempt (a lost connection) lost the timer for good.
                maxAttempts: 3,
                retryBackoffSeconds: 30,
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error("Workflow '{$instance->getWorkflowKey()}' transition '{$appliedKey}': its timeout could not be scheduled; the instance waits until a transition is applied by hand.", [
                'instanceId' => $instance->getId(),
                'message'    => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Schedules attempt $attempt of one side effect, $delaySeconds from now.
     * Never throws: the transition is already committed.
     *
     * @param array<mixed> $context
     */
    public function sideEffectRetry(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        string $sideEffectClass,
        int $attempt,
        int $delaySeconds,
        array $context,
    ): bool {
        if ($this->scheduler === null) {
            $this->logger?->warning("Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}' has a retry policy, but the engine has no scheduler to retry {$sideEffectClass} with.", [
                'instanceId' => $instance->getId(),
            ]);
            return false;
        }
        // The scheduler stores the payload as JSON; a context that is not JSON
        // is left out rather than failing a transition already committed, and
        // one JSON changes (a date becomes an array, an enum its value) is said.
        try {
            $stored = json_decode(json_encode($context, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if ($stored !== $context) {
                $this->logger?->warning("Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}': the context changes when stored as JSON; the retry of {$sideEffectClass} gets the stored form.", [
                    'instanceId' => $instance->getId(),
                ]);
            }
            // The stored form, whatever the scheduler: the retry gets exactly this.
            $context = is_array($stored) ? $stored : [];
        } catch (\JsonException $e) {
            $this->logger?->warning("Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}': the context cannot be stored as JSON; the retry of {$sideEffectClass} runs without it.", [
                'instanceId' => $instance->getId(),
                'message'    => $e->getMessage(),
            ]);
            $context = [];
        }
        try {
            $this->scheduler->dispatchAt(
                jobClass: WorkflowSideEffectRetryJob::class,
                runAt: (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds"),
                payload: [
                    'workflowKey'   => $instance->getWorkflowKey(),
                    'instanceId'    => $instance->getId(),
                    'transitionKey' => $transition->key,
                    'sideEffect'    => $sideEffectClass,
                    'attempt'       => $attempt,
                    'context'       => $context,
                ],
                tenantId: $instance->getTenantId(),
                // Unique per scheduled retry: a lock key only guards one run, and
                // a cyclic workflow can owe the same retry twice at once.
                lockKey: 'workflow_side_effect_' . $instance->getId() . '_' . substr(sha1($transition->key . '|' . $sideEffectClass . '|' . $attempt), 0, 12) . bin2hex(random_bytes(4)),
                // The job itself (not the side effect, which it catches) failing
                // once on a lost connection lost the retry for good.
                maxAttempts: 3,
                retryBackoffSeconds: 30,
            );
        } catch (\Throwable $e) {
            $this->logger?->error("Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}': the retry of {$sideEffectClass} could not be scheduled.", [
                'instanceId' => $instance->getId(),
                'message'    => $e->getMessage(),
            ]);
            return false;
        }
        return true;
    }
}
