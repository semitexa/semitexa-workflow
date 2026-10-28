<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Workflow\Application\Payload\Event\WorkflowFailed;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

/**
 * A side effect that will not run again: the transition did not complete.
 * Logged first, then WorkflowFailed, each give-up on its own: a listener that
 * throws must neither make a committed transition look failed to its caller
 * nor swallow the next give-up.
 */
final class WorkflowFailureReporter
{
    public function __construct(
        private readonly ?LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $events,
    ) {}

    /** @param array{sideEffect: string, code: string, message: string} $failure */
    public function log(WorkflowInstance $instance, string $transitionKey, array $failure, int $attempts): void
    {
        $this->logger?->error("Workflow '{$instance->getWorkflowKey()}' transition '{$transitionKey}': side effect {$failure['sideEffect']} failed {$attempts} " . ($attempts === 1 ? 'time' : 'times') . '; giving up.', [
            'instanceId' => $instance->getId(),
            'tenantId'   => $instance->getTenantId(),
            'code'       => $failure['code'],
            'message'    => $failure['message'],
            'attempts'   => $attempts,
        ]);
    }

    /**
     * $failedState: the state the failed side effect's transition led to. A
     * retry that ran out after the instance moved on reported the state it
     * had moved to, a state the side effect had nothing to do with.
     *
     * @param array{sideEffect: string, code: string, message: string} $failure
     */
    public function dispatch(WorkflowInstance $instance, string $transitionKey, array $failure, ?string $failedState = null): void
    {
        if ($this->events === null) {
            return;
        }
        try {
            $this->events->dispatch($this->events->create(WorkflowFailed::class, [
                'instanceId'     => $instance->getId(),
                'workflowKey'    => $instance->getWorkflowKey(),
                'subjectType'    => $instance->getSubjectType(),
                'subjectId'      => $instance->getSubjectId(),
                'failedState'    => $failedState ?? $instance->getCurrentState(),
                'failureCode'    => $failure['code'],
                'failureMessage' => "{$transitionKey}: {$failure['sideEffect']}: {$failure['message']}",
                'tenantId'       => $instance->getTenantId(),
            ]));
        } catch (\Throwable $e) {
            $this->logger?->error("A WorkflowFailed listener threw for workflow '{$instance->getWorkflowKey()}' instance {$instance->getId()}.", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
