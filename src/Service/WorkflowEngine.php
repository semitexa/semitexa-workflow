<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Transaction\TransactionManager;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Workflow\Application\Payload\Event\WorkflowCompleted;
use Semitexa\Workflow\Application\Payload\Event\WorkflowEnteredWaitingState;
use Semitexa\Workflow\Application\Payload\Event\WorkflowFailed;
use Semitexa\Workflow\Application\Payload\Event\WorkflowManualActionRequired;
use Semitexa\Workflow\Application\Payload\Event\WorkflowStarted;
use Semitexa\Workflow\Application\Payload\Event\WorkflowTransitionApplied;
use Semitexa\Workflow\Application\Payload\Event\WorkflowTransitionRejected;
use Semitexa\Workflow\Contract\WorkflowEngineInterface;
use Semitexa\Workflow\Contract\WorkflowGuardInterface;
use Semitexa\Workflow\Contract\WorkflowInstanceRepositoryInterface;
use Semitexa\Workflow\Contract\WorkflowSideEffectInterface;
use Semitexa\Workflow\Contract\WorkflowSubjectReferenceInterface;
use Semitexa\Workflow\Contract\WorkflowTransitionHistoryRepositoryInterface;
use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Exception\WorkflowAlreadyExistsException;
use Semitexa\Workflow\Domain\Exception\WorkflowInstanceNotFoundException;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;
use Semitexa\Workflow\Domain\Value\WorkflowTransitionResult;
use Semitexa\Workflow\Enum\TransitionResultEnum;
use Semitexa\Workflow\Enum\WorkflowStatus;
use Semitexa\Scheduler\Contract\SchedulerInterface;

#[SatisfiesServiceContract(of: WorkflowEngineInterface::class)]
final class WorkflowEngine implements WorkflowEngineInterface
{
    #[InjectAsReadonly]
    protected ?WorkflowDefinitionRegistry $registry = null;

    #[InjectAsReadonly]
    protected ?WorkflowInstanceRepositoryInterface $instanceRepo = null;

    #[InjectAsReadonly]
    protected ?WorkflowTransitionHistoryRepositoryInterface $historyRepo = null;

    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    #[InjectAsReadonly]
    protected ?EventDispatcherInterface $eventDispatcher = null;

    #[InjectAsReadonly]
    protected ?TransactionManager $transactionManager = null;

    #[InjectAsReadonly]
    protected ?SchedulerInterface $scheduler = null;

    public function start(StartWorkflowCommand $command): WorkflowInstance
    {
        $this->assertDependenciesAvailable();

        $definition = $this->registry->get($command->workflowKey);

        $existing = $this->instanceRepo->findBySubject(
            $command->workflowKey,
            $command->subject->workflowSubjectType(),
            $command->subject->workflowSubjectId(),
        );
        if ($existing !== null) {
            throw new WorkflowAlreadyExistsException(
                $command->workflowKey,
                $command->subject->workflowSubjectType(),
                $command->subject->workflowSubjectId(),
            );
        }

        $instance = new WorkflowInstance();
        $instance->workflowKey = $command->workflowKey;
        $instance->subjectType = $command->subject->workflowSubjectType();
        $instance->subjectId = $command->subject->workflowSubjectId();
        $instance->tenantId = $command->subject->workflowTenantId();
        $instance->currentState = $definition->initialState();
        $instance->status = WorkflowStatus::Active->value;
        $instance->version = 0;
        $instance->payloadJson = $command->payload !== [] ? json_encode($command->payload, JSON_THROW_ON_ERROR) : null;
        $instance->contextJson = $command->context !== [] ? json_encode($command->context, JSON_THROW_ON_ERROR) : null;
        $instance->createdAt = new \DateTimeImmutable();
        $instance->updatedAt = new \DateTimeImmutable();

        $this->instanceRepo->save($instance);

        $this->dispatchEvent(WorkflowStarted::class, [
            'instanceId'   => $instance->id,
            'workflowKey'  => $instance->workflowKey,
            'subjectType'  => $instance->subjectType,
            'subjectId'    => $instance->subjectId,
            'tenantId'     => $instance->tenantId,
            'initialState' => $instance->currentState,
        ]);

        return $instance;
    }

    public function apply(ApplyTransitionCommand $command): WorkflowTransitionResult
    {
        $this->assertDependenciesAvailable();

        $definition = $this->registry->get($command->workflowKey);

        $instance = $this->instanceRepo->findById($command->instanceId);
        if ($instance === null) {
            throw new WorkflowInstanceNotFoundException($command->instanceId);
        }

        // Reject if already in a terminal state
        if (WorkflowStatus::from($instance->status)->isTerminal()) {
            return WorkflowTransitionResult::rejectedInvalid(
                instanceId: $instance->id,
                transitionKey: $command->transitionKey,
                fromState: $instance->currentState,
                failureCode: 'terminal_state',
                failureMessage: "Workflow instance is in terminal status '{$instance->status}' and cannot be transitioned.",
            );
        }

        // Find matching transition definition
        $transition = null;
        foreach ($definition->transitions() as $t) {
            if ($t->key === $command->transitionKey && $t->isValidFrom($instance->currentState)) {
                $transition = $t;
                break;
            }
        }

        if ($transition === null) {
            $this->recordHistory($instance, $instance->currentState, $command, null, TransitionResultEnum::RejectedInvalid, []);
            $this->dispatchEvent(WorkflowTransitionRejected::class, [
                'instanceId'      => $instance->id,
                'workflowKey'     => $instance->workflowKey,
                'transitionKey'   => $command->transitionKey,
                'fromState'       => $instance->currentState,
                'rejectionReason' => "Transition '{$command->transitionKey}' is not valid from state '{$instance->currentState}'",
                'failureCode'     => 'invalid_transition',
                'tenantId'        => $instance->tenantId,
            ]);
            return WorkflowTransitionResult::rejectedInvalid(
                instanceId: $instance->id,
                transitionKey: $command->transitionKey,
                fromState: $instance->currentState,
                failureCode: 'invalid_transition',
                failureMessage: "Transition '{$command->transitionKey}' is not valid from state '{$instance->currentState}'.",
            );
        }

        // Evaluate guards
        $guardFailures = $this->evaluateGuards($transition, $instance, $command);
        if ($guardFailures !== []) {
            $this->recordHistory($instance, $instance->currentState, $command, $transition, TransitionResultEnum::RejectedGuard, $guardFailures);
            $this->dispatchEvent(WorkflowTransitionRejected::class, [
                'instanceId'      => $instance->id,
                'workflowKey'     => $instance->workflowKey,
                'transitionKey'   => $command->transitionKey,
                'fromState'       => $instance->currentState,
                'rejectionReason' => 'One or more guards denied the transition.',
                'failureCode'     => 'guard_denied',
                'tenantId'        => $instance->tenantId,
            ]);
            return WorkflowTransitionResult::rejectedGuard(
                instanceId: $instance->id,
                transitionKey: $command->transitionKey,
                fromState: $instance->currentState,
                guardFailures: $guardFailures,
            );
        }

        // Apply the transition atomically
        $fromState = $instance->currentState;
        $expectedVersion = $instance->version;

        $this->applyStateChange($instance, $transition, $command);

        $committed = $this->commitTransition($instance, $expectedVersion, $fromState, $command, $transition, $guardFailures);
        if (!$committed) {
            return WorkflowTransitionResult::rejectedConflict(
                instanceId: $instance->id,
                transitionKey: $command->transitionKey,
                fromState: $fromState,
            );
        }

        $scheduledFollowUp = false;

        // Run side-effects post-commit (idempotent; failures are logged but do not rollback)
        $sideEffectFailures = $this->runSideEffects($transition, $instance, $command);

        // Schedule timeout job if transition has a timeout policy
        if ($transition->timeout !== null && $this->scheduler !== null) {
            $runAt = (new \DateTimeImmutable())->modify("+{$transition->timeout->afterSeconds} seconds");
            $this->scheduler->dispatchAt(
                jobClass: WorkflowTimeoutJob::class,
                runAt: $runAt,
                payload: [
                    'workflowKey'   => $instance->workflowKey,
                    'instanceId'    => $instance->id,
                    'transitionKey' => $transition->timeout->transitionKey,
                ],
                pool: $transition->timeout->pool,
                tenantId: $instance->tenantId,
                lockKey: "workflow_timeout_{$instance->id}_{$transition->timeout->transitionKey}",
            );
            $scheduledFollowUp = true;
        }

        // Emit post-commit domain events
        $this->emitTransitionEvents($instance, $transition, $command, $fromState, $sideEffectFailures);

        return WorkflowTransitionResult::applied(
            instanceId: $instance->id,
            transitionKey: $command->transitionKey,
            fromState: $fromState,
            toState: $instance->currentState,
            scheduledFollowUp: $scheduledFollowUp,
        );
    }

    public function get(string $instanceId): ?WorkflowInstance
    {
        $this->assertDependenciesAvailable();

        return $this->instanceRepo->findById($instanceId);
    }

    public function findBySubject(string $workflowKey, WorkflowSubjectReferenceInterface $subject): ?WorkflowInstance
    {
        $this->assertDependenciesAvailable();

        return $this->instanceRepo->findBySubject(
            $workflowKey,
            $subject->workflowSubjectType(),
            $subject->workflowSubjectId(),
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertDependenciesAvailable(): void
    {
        if ($this->registry === null || $this->instanceRepo === null || $this->historyRepo === null) {
            throw new \RuntimeException('WorkflowEngine dependencies are not available.');
        }
    }

    private function evaluateGuards(
        TransitionDefinition $transition,
        WorkflowInstance $instance,
        ApplyTransitionCommand $command,
    ): array {
        if ($transition->guards === [] || $this->container === null) {
            return [];
        }

        // Build a minimal subject reference from the instance
        $subjectRef = $this->buildSubjectRef($instance);

        $failures = [];
        foreach ($transition->guards as $guardClass) {
            /** @var WorkflowGuardInterface $guard */
            $guard = $this->container->get($guardClass);
            $result = $guard->evaluate($subjectRef, $instance, $transition, $command->context);
            if (!$result->passed) {
                $failures[] = [
                    'guard'   => $guardClass,
                    'code'    => $result->failureCode ?? 'denied',
                    'message' => $result->failureMessage ?? '',
                ];
            }
        }
        return $failures;
    }

    private function applyStateChange(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ApplyTransitionCommand $command,
    ): void {
        $definition = $this->registry->get($command->workflowKey);

        $instance->currentState = $transition->toState;
        $instance->version++;
        $instance->lastErrorCode = null;
        $instance->lastErrorMessage = null;
        $instance->activeTransitionKey = null;

        // Determine operational status
        if (in_array($transition->toState, $definition->terminalStates(), true)) {
            // Determine whether this is completed or failed based on the transition name convention
            // or simply use "completed" for terminal states reached via normal flow
            $instance->status = WorkflowStatus::Completed->value;
            $instance->completedAt = new \DateTimeImmutable();
            $instance->waitingUntil = null;
            $instance->awaitingManualAction = false;
        } elseif ($transition->requiresManualApproval) {
            $instance->status = WorkflowStatus::AwaitingManualAction->value;
            $instance->awaitingManualAction = true;
            $instance->activeTransitionKey = $transition->key;
        } elseif ($transition->timeout !== null) {
            $instance->status = WorkflowStatus::Waiting->value;
            $instance->waitingUntil = (new \DateTimeImmutable())->modify("+{$transition->timeout->afterSeconds} seconds");
        } else {
            $instance->status = WorkflowStatus::Active->value;
            $instance->waitingUntil = null;
            $instance->awaitingManualAction = false;
        }
    }

    private function commitTransition(
        WorkflowInstance $instance,
        int $expectedVersion,
        string $fromState,
        ApplyTransitionCommand $command,
        TransitionDefinition $transition,
        array $guardFailures,
    ): bool {
        $committed = $this->instanceRepo->saveWithVersionCheck($instance, $expectedVersion);

        if ($committed) {
            $this->recordHistory($instance, $fromState, $command, $transition, TransitionResultEnum::Applied, $guardFailures);
        }

        return $committed;
    }

    private function recordHistory(
        WorkflowInstance $instance,
        string $fromState,
        ApplyTransitionCommand $command,
        ?TransitionDefinition $transition,
        TransitionResultEnum $result,
        array $guardFailures,
    ): void {
        $attemptNumber = $this->historyRepo->countAttempts($instance->id, $command->transitionKey) + 1;

        $history = new WorkflowTransitionHistory();
        $history->workflowInstanceId  = $instance->id;
        $history->transitionKey       = $command->transitionKey;
        $history->fromState           = $fromState;
        $history->toState             = $result->isApplied() ? ($transition?->toState) : null;
        $history->triggerType         = $command->triggerType->value;
        $history->triggeredByType     = $command->triggeredByType;
        $history->triggeredById       = $command->triggeredById;
        $history->attempt             = $attemptNumber;
        $history->result              = $result->value;
        $history->guardFailuresJson   = $guardFailures !== [] ? json_encode($guardFailures, JSON_THROW_ON_ERROR) : null;
        $history->createdAt           = new \DateTimeImmutable();

        $this->historyRepo->save($history);
    }

    private function runSideEffects(
        TransitionDefinition $transition,
        WorkflowInstance $instance,
        ApplyTransitionCommand $command,
    ): array {
        if ($transition->sideEffects === [] || $this->container === null) {
            return [];
        }

        $subjectRef = $this->buildSubjectRef($instance);
        $failures = [];

        foreach ($transition->sideEffects as $sideEffectClass) {
            try {
                /** @var WorkflowSideEffectInterface $sideEffect */
                $sideEffect = $this->container->get($sideEffectClass);
                $result = $sideEffect->execute($subjectRef, $instance, $transition, $command->context);
                if (!$result->succeeded) {
                    $failures[] = [
                        'sideEffect' => $sideEffectClass,
                        'code'       => $result->failureCode ?? 'failed',
                        'message'    => $result->failureMessage ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'sideEffect' => $sideEffectClass,
                    'code'       => 'exception',
                    'message'    => $e->getMessage(),
                ];
            }
        }

        return $failures;
    }

    private function emitTransitionEvents(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        ApplyTransitionCommand $command,
        string $fromState,
        array $sideEffectFailures,
    ): void {
        $definition = $this->registry->get($command->workflowKey);

        $this->dispatchEvent(WorkflowTransitionApplied::class, [
            'instanceId'    => $instance->id,
            'workflowKey'   => $instance->workflowKey,
            'transitionKey' => $command->transitionKey,
            'fromState'     => $fromState,
            'toState'       => $instance->currentState,
            'triggerType'   => $command->triggerType->value,
            'tenantId'      => $instance->tenantId,
        ]);

        if (in_array($instance->currentState, $definition->terminalStates(), true)) {
            $this->dispatchEvent(WorkflowCompleted::class, [
                'instanceId'  => $instance->id,
                'workflowKey' => $instance->workflowKey,
                'subjectType' => $instance->subjectType,
                'subjectId'   => $instance->subjectId,
                'finalState'  => $instance->currentState,
                'tenantId'    => $instance->tenantId,
            ]);
        } elseif ($instance->status === WorkflowStatus::Waiting->value) {
            $this->dispatchEvent(WorkflowEnteredWaitingState::class, [
                'instanceId'   => $instance->id,
                'workflowKey'  => $instance->workflowKey,
                'currentState' => $instance->currentState,
                'waitingUntil' => $instance->waitingUntil?->format(\DateTimeInterface::ATOM),
                'tenantId'     => $instance->tenantId,
            ]);
        } elseif ($instance->awaitingManualAction) {
            $this->dispatchEvent(WorkflowManualActionRequired::class, [
                'instanceId'           => $instance->id,
                'workflowKey'          => $instance->workflowKey,
                'subjectType'          => $instance->subjectType,
                'subjectId'            => $instance->subjectId,
                'currentState'         => $instance->currentState,
                'pendingTransitionKey' => $transition->key,
                'tenantId'             => $instance->tenantId,
            ]);
        }
    }

    private function dispatchEvent(string $eventClass, array $data): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $event = $this->eventDispatcher->create($eventClass, $data);
        $this->eventDispatcher->dispatch($event);
    }

    private function buildSubjectRef(WorkflowInstance $instance): WorkflowSubjectReferenceInterface
    {
        return new class ($instance->subjectType, $instance->subjectId, $instance->tenantId) implements WorkflowSubjectReferenceInterface {
            public function __construct(
                private readonly string $type,
                private readonly string $id,
                private readonly ?string $tenant,
            ) {}

            public function workflowSubjectType(): string { return $this->type; }
            public function workflowSubjectId(): string { return $this->id; }
            public function workflowTenantId(): ?string { return $this->tenant; }
        };
    }
}
