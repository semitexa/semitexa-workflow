<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Workflow\Application\Payload\Event\WorkflowCompleted;
use Semitexa\Workflow\Application\Payload\Event\WorkflowEnteredWaitingState;
use Semitexa\Workflow\Application\Payload\Event\WorkflowManualActionRequired;
use Semitexa\Workflow\Application\Payload\Event\WorkflowStarted;
use Semitexa\Workflow\Application\Payload\Event\WorkflowTransitionApplied;
use Semitexa\Workflow\Application\Payload\Event\WorkflowTransitionRejected;
use Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowGuardInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowInstanceRepositoryInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSideEffectInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowTransitionHistoryRepositoryInterface;
use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Exception\WorkflowAlreadyExistsException;
use Semitexa\Workflow\Domain\Exception\WorkflowInstanceNotFoundException;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionResult;
use Semitexa\Workflow\Domain\Enum\TransitionResultEnum;
use Semitexa\Workflow\Domain\Enum\WorkflowStatus;
use Semitexa\Scheduler\Domain\Contract\SchedulerInterface;

#[SatisfiesServiceContract(of: WorkflowEngineInterface::class)]
final class WorkflowEngine implements WorkflowEngineInterface
{
    #[InjectAsReadonly]
    protected WorkflowDefinitionRegistry $registry;

    #[InjectAsReadonly]
    protected WorkflowInstanceRepositoryInterface $instanceRepo;

    #[InjectAsReadonly]
    protected WorkflowTransitionHistoryRepositoryInterface $historyRepo;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected EventDispatcherInterface $eventDispatcher;

    #[InjectAsReadonly]
    protected TransactionManager $transactionManager;

    #[InjectAsReadonly]
    protected SchedulerInterface $scheduler;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

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
        $instance->setWorkflowKey($command->workflowKey);
        $instance->setSubjectType($command->subject->workflowSubjectType());
        $instance->setSubjectId($command->subject->workflowSubjectId());
        $instance->setTenantId($command->subject->workflowTenantId());
        $instance->setCurrentState($definition->initialState());
        $instance->setStatus(WorkflowStatus::Active->value);
        $instance->setVersion(0);
        $instance->setPayloadJson($command->payload !== [] ? json_encode($command->payload, JSON_THROW_ON_ERROR) : null);
        $instance->setContextJson($command->context !== [] ? json_encode($command->context, JSON_THROW_ON_ERROR) : null);
        $instance->setCreatedAt(new \DateTimeImmutable());
        $instance->setUpdatedAt(new \DateTimeImmutable());

        try {
            $this->instanceRepo->save($instance);
        } catch (ConstraintViolationException $e) {
            // Two starts for one subject can both pass the lookup above; the
            // unique index (workflow_key, subject_type, subject_id) lets only
            // one insert through. The loser gets the same typed answer as a
            // start that found the instance, not a raw constraint error.
            if ($this->instanceRepo->findBySubject(
                $command->workflowKey,
                $command->subject->workflowSubjectType(),
                $command->subject->workflowSubjectId(),
            ) !== null) {
                throw new WorkflowAlreadyExistsException(
                    $command->workflowKey,
                    $command->subject->workflowSubjectType(),
                    $command->subject->workflowSubjectId(),
                );
            }
            throw $e;
        }

        $this->dispatchEvent(WorkflowStarted::class, [
            'instanceId'   => $instance->getId(),
            'workflowKey'  => $instance->getWorkflowKey(),
            'subjectType'  => $instance->getSubjectType(),
            'subjectId'    => $instance->getSubjectId(),
            'tenantId'     => $instance->getTenantId(),
            'initialState' => $instance->getCurrentState(),
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
        if (WorkflowStatus::from($instance->getStatus())->isTerminal()) {
            return WorkflowTransitionResult::rejectedInvalid(
                instanceId: $instance->getId(),
                transitionKey: $command->transitionKey,
                fromState: $instance->getCurrentState(),
                failureCode: 'terminal_state',
                failureMessage: "Workflow instance is in terminal status '{$instance->getStatus()}' and cannot be transitioned.",
            );
        }

        // Find matching transition definition
        $transition = null;
        foreach ($definition->transitions() as $t) {
            if ($t->key === $command->transitionKey && $t->isValidFrom($instance->getCurrentState())) {
                $transition = $t;
                break;
            }
        }

        if ($transition === null) {
            $this->recordHistory($instance, $instance->getCurrentState(), $command, null, TransitionResultEnum::RejectedInvalid, []);
            $this->dispatchEvent(WorkflowTransitionRejected::class, [
                'instanceId'      => $instance->getId(),
                'workflowKey'     => $instance->getWorkflowKey(),
                'transitionKey'   => $command->transitionKey,
                'fromState'       => $instance->getCurrentState(),
                'rejectionReason' => "Transition '{$command->transitionKey}' is not valid from state '{$instance->getCurrentState()}'",
                'failureCode'     => 'invalid_transition',
                'tenantId'        => $instance->getTenantId(),
            ]);
            return WorkflowTransitionResult::rejectedInvalid(
                instanceId: $instance->getId(),
                transitionKey: $command->transitionKey,
                fromState: $instance->getCurrentState(),
                failureCode: 'invalid_transition',
                failureMessage: "Transition '{$command->transitionKey}' is not valid from state '{$instance->getCurrentState()}'.",
            );
        }

        // Evaluate guards
        $guardFailures = $this->evaluateGuards($transition, $instance, $command);
        if ($guardFailures !== []) {
            $this->recordHistory($instance, $instance->getCurrentState(), $command, $transition, TransitionResultEnum::RejectedGuard, $guardFailures);
            $this->dispatchEvent(WorkflowTransitionRejected::class, [
                'instanceId'      => $instance->getId(),
                'workflowKey'     => $instance->getWorkflowKey(),
                'transitionKey'   => $command->transitionKey,
                'fromState'       => $instance->getCurrentState(),
                'rejectionReason' => 'One or more guards denied the transition.',
                'failureCode'     => 'guard_denied',
                'tenantId'        => $instance->getTenantId(),
            ]);
            return WorkflowTransitionResult::rejectedGuard(
                instanceId: $instance->getId(),
                transitionKey: $command->transitionKey,
                fromState: $instance->getCurrentState(),
                guardFailures: $guardFailures,
            );
        }

        // Apply the transition atomically
        $fromState = $instance->getCurrentState();
        $expectedVersion = $instance->getVersion();

        $this->applyStateChange($instance, $transition, $command);

        $committed = $this->commitTransition($instance, $expectedVersion, $fromState, $command, $transition, $guardFailures);
        if (!$committed) {
            return WorkflowTransitionResult::rejectedConflict(
                instanceId: $instance->getId(),
                transitionKey: $command->transitionKey,
                fromState: $fromState,
            );
        }

        $scheduledFollowUp = false;

        // Run side-effects post-commit (idempotent; failures are logged but do not rollback)
        $sideEffectFailures = $this->runSideEffects($transition, $instance, $command);
        if ($sideEffectFailures !== [] && isset($this->logger)) {
            $this->logger->warning("Workflow '{$instance->getWorkflowKey()}' transition '{$command->transitionKey}' was applied, but side effects failed.", [
                'instanceId' => $instance->getId(),
                'tenantId'   => $instance->getTenantId(),
                'failures'   => $sideEffectFailures,
            ]);
        }
        // A transition with a RetryPolicy gets each failed side effect run again later.
        if ($transition->retryPolicy !== null && $transition->retryPolicy->maxAttempts > 1) {
            foreach ($sideEffectFailures as $failure) {
                $scheduledFollowUp = $this->scheduleSideEffectRetry(
                    $instance,
                    $transition,
                    $failure['sideEffect'],
                    2,
                    $transition->retryPolicy->backoffForAttempt(1),
                    $command->context,
                ) || $scheduledFollowUp;
            }
        }

        // Schedule timeout job if transition has a timeout policy
        if ($transition->timeout !== null && isset($this->scheduler)) {
            $runAt = (new \DateTimeImmutable())->modify("+{$transition->timeout->afterSeconds} seconds");
            $this->scheduler->dispatchAt(
                jobClass: WorkflowTimeoutJob::class,
                runAt: $runAt,
                payload: [
                    'workflowKey'   => $instance->getWorkflowKey(),
                    'instanceId'    => $instance->getId(),
                    'transitionKey' => $transition->timeout->transitionKey,
                ],
                pool: $transition->timeout->pool,
                tenantId: $instance->getTenantId(),
                lockKey: "workflow_timeout_{$instance->getId()}_{$transition->timeout->transitionKey}",
            );
            $scheduledFollowUp = true;
        }

        // Emit post-commit domain events
        $this->emitTransitionEvents($instance, $transition, $command, $fromState, $sideEffectFailures);

        return WorkflowTransitionResult::applied(
            instanceId: $instance->getId(),
            transitionKey: $command->transitionKey,
            fromState: $fromState,
            toState: $instance->getCurrentState(),
            scheduledFollowUp: $scheduledFollowUp,
        );
    }

    public function retrySideEffect(string $workflowKey, string $instanceId, string $transitionKey, string $sideEffectClass, int $attempt, array $context = []): void
    {
        $this->assertDependenciesAvailable();

        $definition = $this->registry->get($workflowKey);
        $instance = $this->instanceRepo->findById($instanceId);
        if ($instance === null) {
            $this->log('warning', "Workflow '{$workflowKey}' instance {$instanceId} no longer exists; the retry of {$sideEffectClass} is dropped.", [
                'transitionKey' => $transitionKey,
            ]);
            return;
        }
        // The job's payload is read back from storage: only a side effect the
        // transition declares is run.
        $transition = null;
        foreach ($definition->transitions() as $t) {
            if ($t->key === $transitionKey && in_array($sideEffectClass, $t->sideEffects, true)) {
                $transition = $t;
                break;
            }
        }
        if ($transition === null) {
            $this->log('warning', "Workflow '{$workflowKey}' transition '{$transitionKey}' declares no side effect {$sideEffectClass}; the retry is dropped.", [
                'instanceId' => $instanceId,
            ]);
            return;
        }

        $failure = isset($this->container)
            ? $this->runSideEffect($sideEffectClass, $this->buildSubjectRef($instance), $instance, $transition, $context)
            : ['sideEffect' => $sideEffectClass, 'code' => 'no_container', 'message' => 'The engine has no container to build this side effect with.'];
        if ($failure === null) {
            return;
        }

        $policy = $transition->retryPolicy;
        if ($policy !== null && $attempt < $policy->maxAttempts
            && $this->scheduleSideEffectRetry($instance, $transition, $sideEffectClass, $attempt + 1, $policy->backoffForAttempt($attempt), $context)) {
            return;
        }

        $this->log('error', "Workflow '{$workflowKey}' transition '{$transitionKey}': side effect {$sideEffectClass} failed {$attempt} times; giving up.", [
            'instanceId' => $instanceId,
            'tenantId'   => $instance->getTenantId(),
            'code'       => $failure['code'],
            'message'    => $failure['message'],
            'attempts'   => $attempt,
        ]);
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
        if (!isset($this->registry) || !isset($this->instanceRepo) || !isset($this->historyRepo)) {
            throw new \RuntimeException('WorkflowEngine dependencies are not available.');
        }
    }

    private function evaluateGuards(
        TransitionDefinition $transition,
        WorkflowInstance $instance,
        ApplyTransitionCommand $command,
    ): array {
        if ($transition->guards === []) {
            return [];
        }
        // A guard is a rule, often "may this user". One that cannot be built
        // must stop the transition, not be skipped.
        if (!isset($this->container)) {
            throw new \LogicException(sprintf(
                "Transition '%s' is guarded by %s, but the engine has no container to build the guards with.",
                $transition->key,
                implode(', ', $transition->guards),
            ));
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

        $instance->setCurrentState($transition->toState);
        $instance->setVersion($instance->getVersion() + 1);
        $instance->setLastErrorCode(null);
        $instance->setLastErrorMessage(null);
        $instance->setActiveTransitionKey(null);

        // Determine operational status
        if (in_array($transition->toState, $definition->terminalStates(), true)) {
            // Determine whether this is completed or failed based on the transition name convention
            // or simply use "completed" for terminal states reached via normal flow
            $instance->setStatus(WorkflowStatus::Completed->value);
            $instance->setCompletedAt(new \DateTimeImmutable());
            $instance->setWaitingUntil(null);
            $instance->setAwaitingManualAction(false);
        } elseif ($transition->requiresManualApproval) {
            $instance->setStatus(WorkflowStatus::AwaitingManualAction->value);
            $instance->setAwaitingManualAction(true);
            $instance->setActiveTransitionKey($transition->key);
        } elseif ($transition->timeout !== null) {
            $instance->setStatus(WorkflowStatus::Waiting->value);
            $instance->setWaitingUntil((new \DateTimeImmutable())->modify("+{$transition->timeout->afterSeconds} seconds"));
        } else {
            $instance->setStatus(WorkflowStatus::Active->value);
            $instance->setWaitingUntil(null);
            $instance->setAwaitingManualAction(false);
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
        $attemptNumber = $this->historyRepo->countAttempts($instance->getId(), $command->transitionKey) + 1;

        $history = new WorkflowTransitionHistory();
        $history->setWorkflowInstanceId($instance->getId());
        $history->setTransitionKey($command->transitionKey);
        $history->setFromState($fromState);
        $history->setToState($result->isApplied() ? ($transition?->toState) : null);
        $history->setTriggerType($command->triggerType->value);
        $history->setTriggeredByType($command->triggeredByType);
        $history->setTriggeredById($command->triggeredById);
        $history->setAttempt($attemptNumber);
        $history->setResult($result->value);
        $history->setGuardFailuresJson($guardFailures !== [] ? json_encode($guardFailures, JSON_THROW_ON_ERROR) : null);
        $history->setCreatedAt(new \DateTimeImmutable());

        $this->historyRepo->save($history);
    }

    /** @return list<array{sideEffect: string, code: string, message: string}> */
    private function runSideEffects(
        TransitionDefinition $transition,
        WorkflowInstance $instance,
        ApplyTransitionCommand $command,
    ): array {
        if ($transition->sideEffects === []) {
            return [];
        }
        // Not skipped in silence: the transition is committed, so a side effect
        // that cannot be built is reported like one that failed.
        if (!isset($this->container)) {
            return array_map(static fn (string $class): array => [
                'sideEffect' => $class,
                'code'       => 'no_container',
                'message'    => 'The engine has no container to build this side effect with.',
            ], $transition->sideEffects);
        }

        $subjectRef = $this->buildSubjectRef($instance);
        $failures = [];

        foreach ($transition->sideEffects as $sideEffectClass) {
            $failure = $this->runSideEffect($sideEffectClass, $subjectRef, $instance, $transition, $command->context);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $context
     * @return array{sideEffect: string, code: string, message: string}|null null when it succeeded
     */
    private function runSideEffect(
        string $sideEffectClass,
        WorkflowSubjectReferenceInterface $subjectRef,
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        array $context,
    ): ?array {
        try {
            /** @var WorkflowSideEffectInterface $sideEffect */
            $sideEffect = $this->container->get($sideEffectClass);
            $result = $sideEffect->execute($subjectRef, $instance, $transition, $context);
            if ($result->succeeded) {
                return null;
            }
            return [
                'sideEffect' => $sideEffectClass,
                'code'       => $result->failureCode ?? 'failed',
                'message'    => $result->failureMessage ?? '',
            ];
        } catch (\Throwable $e) {
            return [
                'sideEffect' => $sideEffectClass,
                'code'       => 'exception',
                'message'    => $e->getMessage(),
            ];
        }
    }

    /**
     * Schedules attempt $attempt of one side effect, $delaySeconds from now.
     * Never throws: the transition is already committed.
     *
     * @param array<mixed> $context
     */
    private function scheduleSideEffectRetry(
        WorkflowInstance $instance,
        TransitionDefinition $transition,
        string $sideEffectClass,
        int $attempt,
        int $delaySeconds,
        array $context,
    ): bool {
        if (!isset($this->scheduler)) {
            $this->log('warning', "Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}' has a retry policy, but the engine has no scheduler to retry {$sideEffectClass} with.", [
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
                $this->log('warning', "Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}': the context changes when stored as JSON; the retry of {$sideEffectClass} gets the stored form.", [
                    'instanceId' => $instance->getId(),
                ]);
            }
            // The stored form, whatever the scheduler: the retry gets exactly this.
            $context = is_array($stored) ? $stored : [];
        } catch (\JsonException $e) {
            $this->log('warning', "Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}': the context cannot be stored as JSON; the retry of {$sideEffectClass} runs without it.", [
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
            );
        } catch (\Throwable $e) {
            $this->log('error', "Workflow '{$instance->getWorkflowKey()}' transition '{$transition->key}': the retry of {$sideEffectClass} could not be scheduled.", [
                'instanceId' => $instance->getId(),
                'message'    => $e->getMessage(),
            ]);
            return false;
        }
        return true;
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
            'instanceId'    => $instance->getId(),
            'workflowKey'   => $instance->getWorkflowKey(),
            'transitionKey' => $command->transitionKey,
            'fromState'     => $fromState,
            'toState'       => $instance->getCurrentState(),
            'triggerType'   => $command->triggerType->value,
            'tenantId'      => $instance->getTenantId(),
        ]);

        if (in_array($instance->getCurrentState(), $definition->terminalStates(), true)) {
            $this->dispatchEvent(WorkflowCompleted::class, [
                'instanceId'  => $instance->getId(),
                'workflowKey' => $instance->getWorkflowKey(),
                'subjectType' => $instance->getSubjectType(),
                'subjectId'   => $instance->getSubjectId(),
                'finalState'  => $instance->getCurrentState(),
                'tenantId'    => $instance->getTenantId(),
            ]);
        } elseif ($instance->getStatus() === WorkflowStatus::Waiting->value) {
            $this->dispatchEvent(WorkflowEnteredWaitingState::class, [
                'instanceId'   => $instance->getId(),
                'workflowKey'  => $instance->getWorkflowKey(),
                'currentState' => $instance->getCurrentState(),
                'waitingUntil' => $instance->getWaitingUntil()?->format(\DateTimeInterface::ATOM),
                'tenantId'     => $instance->getTenantId(),
            ]);
        } elseif ($instance->isAwaitingManualAction()) {
            $this->dispatchEvent(WorkflowManualActionRequired::class, [
                'instanceId'           => $instance->getId(),
                'workflowKey'          => $instance->getWorkflowKey(),
                'subjectType'          => $instance->getSubjectType(),
                'subjectId'            => $instance->getSubjectId(),
                'currentState'         => $instance->getCurrentState(),
                'pendingTransitionKey' => $transition->key,
                'tenantId'             => $instance->getTenantId(),
            ]);
        }
    }

    /**
     * @param 'warning'|'error' $level
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (isset($this->logger)) {
            $this->logger->{$level}($message, $context);
        }
    }

    private function dispatchEvent(string $eventClass, array $data): void
    {
        if (!isset($this->eventDispatcher)) {
            return;
        }
        $event = $this->eventDispatcher->create($eventClass, $data);
        $this->eventDispatcher->dispatch($event);
    }

    private function buildSubjectRef(WorkflowInstance $instance): WorkflowSubjectReferenceInterface
    {
        return new class ($instance->getSubjectType(), $instance->getSubjectId(), $instance->getTenantId()) implements WorkflowSubjectReferenceInterface {
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
