<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Workflow\Application\Payload\Event\WorkflowCompleted;
use Semitexa\Workflow\Application\Payload\Event\WorkflowEnteredWaitingState;
use Semitexa\Workflow\Application\Payload\Event\WorkflowManualActionRequired;
use Semitexa\Workflow\Application\Payload\Event\WorkflowStarted;
use Semitexa\Workflow\Application\Payload\Event\WorkflowTransitionApplied;
use Semitexa\Workflow\Application\Payload\Event\WorkflowTransitionRejected;
use Semitexa\Workflow\Application\Service\WorkflowDefinitionRegistry;
use Semitexa\Workflow\Application\Service\WorkflowEngine;
use Semitexa\Workflow\Application\Service\WorkflowSideEffectRetryJob;
use Semitexa\Workflow\Application\Service\WorkflowTimeoutJob;
use Semitexa\Workflow\Domain\Command\ApplyTransitionCommand;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowGuardInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowInstanceRepositoryInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSideEffectInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowTransitionHistoryRepositoryInterface;
use Semitexa\Workflow\Domain\Enum\TransitionResultEnum;
use Semitexa\Workflow\Domain\Enum\TriggerType;
use Semitexa\Workflow\Domain\Model\GuardResult;
use Semitexa\Workflow\Domain\Model\RetryPolicy;
use Semitexa\Workflow\Domain\Model\SideEffectResult;
use Semitexa\Workflow\Domain\Model\TimeoutPolicy;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

/**
 * The engine itself — transitions, guards, the terminal state, the version
 * check, timeouts through the scheduler and side effects — against in-memory
 * stores (tk-parity-proof-gaps). Until this test only the start race was
 * proven.
 */
final class WorkflowEngineTest extends TestCase
{
    private EngineInstances $instances;

    private EngineHistory $history;

    private EngineEvents $events;

    private EngineScheduler $scheduler;

    private EngineLog $log;

    protected function setUp(): void
    {
        $this->instances = new EngineInstances();
        $this->history = new EngineHistory();
        $this->events = new EngineEvents();
        $this->scheduler = new EngineScheduler();
        $this->log = new EngineLog();
        EngineFixtures::$approved = true;
        EngineFixtures::$sideEffectRuns = 0;
        EngineFixtures::$flakyFailuresLeft = 0;
        EngineFixtures::$flakyRuns = 0;
    }

    #[Test]
    public function a_started_workflow_moves_along_its_transitions_and_records_each(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay'));

        self::assertSame(['new', 'active'], [$order->getCurrentState(), $order->getStatus()], 'started in the initial state, active');
        self::assertSame(TransitionResultEnum::Applied, $result->result);
        self::assertSame(['new', 'paid'], [$result->fromState, $result->toState]);
        $stored = $this->instances->findById($order->getId());
        self::assertSame(['paid', 'active', 1], [$stored?->getCurrentState(), $stored?->getStatus(), $stored?->getVersion()]);
        self::assertSame([['pay', 'new', 'paid', 'applied', 1]], $this->history->rows());
        self::assertSame([WorkflowStarted::class, WorkflowTransitionApplied::class], $this->events->classes());
    }

    #[Test]
    public function a_transition_not_allowed_from_the_current_state_is_rejected_and_changes_nothing(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'ship'));

        self::assertSame(TransitionResultEnum::RejectedInvalid, $result->result);
        self::assertSame('invalid_transition', $result->failureCode);
        self::assertSame(['new', 0], [$this->instances->findById($order->getId())?->getCurrentState(), $this->instances->findById($order->getId())?->getVersion()]);
        self::assertSame([['ship', 'new', null, 'rejected_invalid', 1]], $this->history->rows());
        self::assertContains(WorkflowTransitionRejected::class, $this->events->classes());
    }

    #[Test]
    public function a_denying_guard_stops_the_transition_and_says_which_and_why(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());
        $engine->apply($this->command($order, 'pay'));
        EngineFixtures::$approved = false;

        $denied = $engine->apply($this->command($order, 'ship'));
        EngineFixtures::$approved = true;
        $allowed = $engine->apply($this->command($order, 'ship'));

        self::assertSame(TransitionResultEnum::RejectedGuard, $denied->result);
        self::assertSame([['guard' => EngineApprovalGuard::class, 'code' => 'not_approved', 'message' => 'Awaiting approval.']], $denied->guardFailures);
        self::assertSame(TransitionResultEnum::Applied, $allowed->result);
        self::assertSame([1, 2], [$this->history->rows()[1][4], $this->history->rows()[2][4]], 'the retried transition counts as the second attempt');
    }

    /**
     * A guard is a rule — often "may this user". With no container to build
     * it, the engine used to skip every guard and let the transition through.
     */
    #[Test]
    public function a_guard_that_cannot_be_built_does_not_let_the_transition_through(): void
    {
        $engine = $this->engine(container: false);
        $order = $engine->start($this->startOrder());
        $engine->apply($this->command($order, 'pay'));
        EngineFixtures::$approved = false;

        try {
            $engine->apply($this->command($order, 'ship'));
            self::fail('a guarded transition went through without its guard');
        } catch (\LogicException $e) {
            self::assertStringContainsString(EngineApprovalGuard::class, $e->getMessage());
        }
        self::assertSame('paid', $this->instances->findById($order->getId())?->getCurrentState());
    }

    #[Test]
    public function reaching_a_terminal_state_completes_the_workflow_and_ends_it(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());
        $engine->apply($this->command($order, 'pay'));
        $engine->apply($this->command($order, 'ship'));

        $after = $engine->apply($this->command($order, 'refund'));

        $stored = $this->instances->findById($order->getId());
        self::assertSame(['shipped', 'completed'], [$stored?->getCurrentState(), $stored?->getStatus()]);
        self::assertNotNull($stored?->getCompletedAt());
        self::assertContains(WorkflowCompleted::class, $this->events->classes());
        self::assertSame([TransitionResultEnum::RejectedInvalid, 'terminal_state'], [$after->result, $after->failureCode]);
    }

    #[Test]
    public function a_transition_applied_meanwhile_by_someone_else_is_a_conflict_not_an_overwrite(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());
        $this->instances->anotherWriterCommitsBeforeTheNextWrite();

        $result = $engine->apply($this->command($order, 'pay'));

        self::assertSame(TransitionResultEnum::RejectedConflict, $result->result);
        self::assertSame('new', $this->instances->findById($order->getId())?->getCurrentState());
        self::assertSame([], $this->history->rows(), 'nothing applied, nothing recorded as applied');
    }

    #[Test]
    public function a_timeout_waits_and_schedules_its_transition_which_the_job_then_applies(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $sent = $engine->apply($this->command($order, 'send_quote'));

        $stored = $this->instances->findById($order->getId());
        self::assertTrue($sent->scheduledFollowUp);
        self::assertSame(['quoted', 'waiting'], [$stored?->getCurrentState(), $stored?->getStatus()]);
        self::assertEqualsWithDelta(time() + 3600, $stored?->getWaitingUntil()?->getTimestamp(), 5);
        self::assertContains(WorkflowEnteredWaitingState::class, $this->events->classes());
        self::assertCount(1, $this->scheduler->jobs);
        $job = $this->scheduler->jobs[0];
        self::assertSame(WorkflowTimeoutJob::class, $job['jobClass']);
        self::assertEqualsWithDelta(time() + 3600, $job['runAt']->getTimestamp(), 5);
        self::assertSame("workflow_timeout_{$order->getId()}_expire", $job['lockKey'], 'one timeout per instance and transition');

        $this->runTimeoutJob($engine, $job['payload']);

        $expired = $this->instances->findById($order->getId());
        self::assertSame('expired', $expired?->getCurrentState());
        self::assertSame('scheduled', $this->history->all()[1]->getTriggerType());
    }

    #[Test]
    public function a_timeout_that_fires_after_the_workflow_moved_on_changes_nothing(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());
        $engine->apply($this->command($order, 'send_quote'));
        $payload = $this->scheduler->jobs[0]['payload'];
        $engine->apply($this->command($order, 'accept'));

        $this->runTimeoutJob($engine, $payload);

        self::assertSame('paid', $this->instances->findById($order->getId())?->getCurrentState());
    }

    #[Test]
    public function a_transition_that_needs_approval_waits_for_a_person(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $engine->apply($this->command($order, 'flag'));

        $stored = $this->instances->findById($order->getId());
        self::assertSame(['review', 'awaiting_manual_action', true], [$stored?->getCurrentState(), $stored?->getStatus(), $stored?->isAwaitingManualAction()]);
        self::assertContains(WorkflowManualActionRequired::class, $this->events->classes());
    }

    /**
     * Side effects run after the commit and do not undo it. Their failures were
     * collected and then dropped — "logged", said the comment, by nothing.
     */
    #[Test]
    public function a_failing_side_effect_keeps_the_transition_and_is_reported(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay'));

        self::assertSame(TransitionResultEnum::Applied, $result->result);
        self::assertSame(1, EngineFixtures::$sideEffectRuns);
        self::assertSame('paid', $this->instances->findById($order->getId())?->getCurrentState());
        self::assertCount(1, $this->log->warnings);
        self::assertStringContainsString('pay', $this->log->warnings[0][0]);
        self::assertSame([['sideEffect' => EngineReceiptSideEffect::class, 'code' => 'mailer_down', 'message' => 'SMTP refused']], $this->log->warnings[0][1]['failures']);
    }

    /**
     * The other half of the guard rule: a side effect that cannot be built is
     * not silently skipped. The transition is already committed, so it is not
     * undone — it is reported, as a failing side effect is.
     */
    #[Test]
    public function a_side_effect_that_cannot_be_built_is_reported_not_skipped(): void
    {
        $engine = $this->engine(container: false);
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay'));

        self::assertSame(TransitionResultEnum::Applied, $result->result);
        self::assertSame(0, EngineFixtures::$sideEffectRuns);
        self::assertCount(1, $this->log->warnings);
        self::assertSame([['sideEffect' => EngineReceiptSideEffect::class, 'code' => 'no_container', 'message' => 'The engine has no container to build this side effect with.']], $this->log->warnings[0][1]['failures']);
    }

    /**
     * REGRESSION (tk-workflow-retry-policy-applied). A transition's RetryPolicy
     * was accepted and never read: a failing side effect ran once, whatever the
     * policy said. It is now retried through the scheduler, each wait the
     * policy's backoff (doubling here, capped at 45 s), and given up after
     * maxAttempts attempts in all, the first one included.
     */
    #[Test]
    public function a_failing_side_effect_is_retried_as_its_retry_policy_says_then_given_up(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay_retried', ['note' => 'gift']));

        self::assertTrue($result->scheduledFollowUp, 'a retry is scheduled');
        self::assertSame(1, EngineFixtures::$sideEffectRuns);
        self::assertEqualsWithDelta(30, $this->retryDelay(0), 1, 'first wait: backoffSeconds');
        self::assertSame([
            'workflowKey' => 'order',
            'instanceId' => $order->getId(),
            'transitionKey' => 'pay_retried',
            'sideEffect' => EngineReceiptSideEffect::class,
            'attempt' => 2,
            'context' => ['note' => 'gift'],
        ], $this->scheduler->jobs[0]['payload']);

        $this->runRetryJob($engine, $this->scheduler->jobs[0]['payload']);
        self::assertSame(2, EngineFixtures::$sideEffectRuns);
        self::assertCount(2, $this->scheduler->jobs);
        self::assertEqualsWithDelta(45, $this->retryDelay(1), 1, 'second wait: 30 x 2 = 60, capped at maxBackoffSeconds');
        self::assertSame(3, $this->scheduler->jobs[1]['payload']['attempt']);

        $this->runRetryJob($engine, $this->scheduler->jobs[1]['payload']);
        self::assertSame(3, EngineFixtures::$sideEffectRuns, 'maxAttempts: 3 runs in all');
        self::assertCount(2, $this->scheduler->jobs, 'no fourth attempt');
        self::assertCount(1, $this->log->errors);
        self::assertSame("Workflow 'order' transition 'pay_retried': side effect " . EngineReceiptSideEffect::class . ' failed 3 times; giving up.', $this->log->errors[0][0]);
        self::assertSame(['code' => 'mailer_down', 'message' => 'SMTP refused'], array_intersect_key($this->log->errors[0][1], ['code' => 1, 'message' => 1]));
    }

    #[Test]
    public function a_side_effect_that_succeeds_on_a_retry_is_not_retried_again(): void
    {
        EngineFixtures::$flakyFailuresLeft = 1;
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());
        $engine->apply($this->command($order, 'pay_flaky'));
        self::assertCount(1, $this->scheduler->jobs);

        $this->runRetryJob($engine, $this->scheduler->jobs[0]['payload']);

        self::assertSame(2, EngineFixtures::$flakyRuns, 'inline once, failing; the retry once, succeeding');
        self::assertCount(1, $this->scheduler->jobs, 'nothing more scheduled');
        self::assertSame([], $this->log->errors);
    }

    #[Test]
    public function without_a_retry_policy_a_failing_side_effect_runs_once(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay'));

        self::assertFalse($result->scheduledFollowUp);
        self::assertSame([], $this->scheduler->jobs);
    }

    /** The job's payload is read back from storage: it reruns only a side effect that transition declares. */
    #[Test]
    public function a_retry_naming_a_class_the_transition_does_not_declare_runs_nothing(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());
        $engine->apply($this->command($order, 'pay_retried'));
        $payload = ['sideEffect' => EngineFlakySideEffect::class] + $this->scheduler->jobs[0]['payload'];

        $this->runRetryJob($engine, $payload);

        self::assertSame(1, EngineFixtures::$sideEffectRuns);
        self::assertSame(0, EngineFixtures::$flakyRuns);
        self::assertCount(1, $this->scheduler->jobs);
    }

    /** A context that cannot be stored as JSON is left out of the retry instead of breaking a committed transition. */
    #[Test]
    public function a_context_that_is_not_json_does_not_break_the_transition(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay_retried', ['stream' => fopen('php://memory', 'r')]));

        self::assertSame(TransitionResultEnum::Applied, $result->result);
        self::assertSame([], $this->scheduler->jobs[0]['payload']['context']);
        self::assertStringContainsString('the context cannot be stored as JSON', $this->log->warnings[1][0]);
    }

    #[Test]
    public function a_context_json_would_change_is_stored_with_a_warning(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $engine->apply($this->command($order, 'pay_retried', ['at' => new \DateTimeImmutable('2026-10-20 10:00:00')]));

        self::assertSame(
            ['at' => ['date' => '2026-10-20 10:00:00.000000', 'timezone_type' => 3, 'timezone' => date_default_timezone_get()]],
            $this->scheduler->jobs[0]['payload']['context'],
            'what the retry will get',
        );
        self::assertStringContainsString('the context changes when stored as JSON', $this->log->warnings[1][0]);
    }

    #[Test]
    public function a_retry_for_an_instance_that_is_gone_is_dropped_and_said(): void
    {
        $engine = $this->engine();

        $engine->retrySideEffect('order', 'gone', 'pay_retried', EngineReceiptSideEffect::class, 2);

        self::assertSame(0, EngineFixtures::$sideEffectRuns);
        self::assertStringContainsString('instance gone no longer exists', $this->log->warnings[0][0]);
    }

    #[Test]
    public function a_backoff_is_never_negative(): void
    {
        self::assertSame(0, (new RetryPolicy(backoffSeconds: -60))->backoffForAttempt(1));
        self::assertSame(10, (new RetryPolicy(backoffSeconds: 10, backoffMultiplier: 3.0, maxBackoffSeconds: 100))->backoffForAttempt(1));
        self::assertSame(90, (new RetryPolicy(backoffSeconds: 10, backoffMultiplier: 3.0, maxBackoffSeconds: 100))->backoffForAttempt(3));
        self::assertSame(100, (new RetryPolicy(backoffSeconds: 10, backoffMultiplier: 3.0, maxBackoffSeconds: 100))->backoffForAttempt(4));
    }

    private function retryDelay(int $job): int
    {
        self::assertSame(WorkflowSideEffectRetryJob::class, $this->scheduler->jobs[$job]['jobClass']);

        return $this->scheduler->jobs[$job]['runAt']->getTimestamp() - time();
    }

    /** @param array<string, mixed> $payload */
    private function runRetryJob(WorkflowEngine $engine, array $payload): void
    {
        $job = new WorkflowSideEffectRetryJob();
        (new \ReflectionProperty(WorkflowSideEffectRetryJob::class, 'engine'))->setValue($job, $engine);
        $job->handle(new ScheduledJobContext(runId: 'run-r', jobClass: WorkflowSideEffectRetryJob::class, pool: 'default', payload: $payload));
    }

    /** @param array<string, mixed> $payload */
    private function runTimeoutJob(WorkflowEngine $engine, array $payload): void
    {
        $job = new WorkflowTimeoutJob();
        (new \ReflectionProperty(WorkflowTimeoutJob::class, 'engine'))->setValue($job, $engine);
        $job->handle(new ScheduledJobContext(runId: 'run-1', jobClass: WorkflowTimeoutJob::class, pool: 'default', payload: $payload));
    }

    private function engine(bool $container = true): WorkflowEngine
    {
        $registry = (new \ReflectionClass(WorkflowDefinitionRegistry::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($registry, 'definitions'))->setValue($registry, ['order' => new EngineOrderWorkflow()]);
        (new \ReflectionProperty($registry, 'initialized'))->setValue($registry, true);

        $engine = (new \ReflectionClass(WorkflowEngine::class))->newInstanceWithoutConstructor();
        $properties = [
            'registry' => $registry,
            'instanceRepo' => $this->instances,
            'historyRepo' => $this->history,
            'eventDispatcher' => $this->events,
            'scheduler' => $this->scheduler,
            'logger' => $this->log,
        ];
        if ($container) {
            $properties['container'] = new EngineContainer();
        }
        foreach ($properties as $name => $value) {
            (new \ReflectionProperty(WorkflowEngine::class, $name))->setValue($engine, $value);
        }

        return $engine;
    }

    private function startOrder(): StartWorkflowCommand
    {
        return new StartWorkflowCommand('order', new class implements WorkflowSubjectReferenceInterface {
            public function workflowSubjectType(): string { return 'order'; }
            public function workflowSubjectId(): string { return 'order-1'; }
            public function workflowTenantId(): ?string { return 't-1'; }
        });
    }

    /** @param array<string, mixed> $context */
    private function command(WorkflowInstance $instance, string $transition, array $context = []): ApplyTransitionCommand
    {
        return new ApplyTransitionCommand('order', $instance->getId(), $transition, TriggerType::Manual, 'user', 'u-1', $context);
    }
}

final class EngineOrderWorkflow implements WorkflowDefinitionInterface
{
    public static function key(): string { return 'order'; }
    public function initialState(): string { return 'new'; }
    public function states(): array { return ['new', 'paid', 'shipped', 'quoted', 'expired', 'review']; }
    public function terminalStates(): array { return ['shipped', 'expired']; }

    public function transitions(): array
    {
        return [
            new TransitionDefinition('pay', ['new'], 'paid', sideEffects: [EngineReceiptSideEffect::class]),
            new TransitionDefinition('ship', ['paid'], 'shipped', guards: [EngineApprovalGuard::class]),
            new TransitionDefinition('refund', ['paid', 'shipped'], 'new'),
            new TransitionDefinition('send_quote', ['new'], 'quoted', timeout: new TimeoutPolicy(3600, 'expire')),
            new TransitionDefinition('accept', ['quoted'], 'paid'),
            new TransitionDefinition('expire', ['quoted'], 'expired', triggerType: TriggerType::Scheduled),
            new TransitionDefinition('flag', ['new'], 'review', requiresManualApproval: true),
            new TransitionDefinition('pay_retried', ['new'], 'paid', sideEffects: [EngineReceiptSideEffect::class], retryPolicy: new RetryPolicy(maxAttempts: 3, backoffSeconds: 30, backoffMultiplier: 2.0, maxBackoffSeconds: 45)),
            new TransitionDefinition('pay_flaky', ['new'], 'paid', sideEffects: [EngineFlakySideEffect::class], retryPolicy: new RetryPolicy(maxAttempts: 3, backoffSeconds: 30)),
        ];
    }
}

final class EngineFixtures
{
    public static bool $approved = true;

    public static int $sideEffectRuns = 0;

    public static int $flakyFailuresLeft = 0;

    public static int $flakyRuns = 0;
}

/** Fails as many times as $flakyFailuresLeft says, then succeeds. */
final class EngineFlakySideEffect implements WorkflowSideEffectInterface
{
    /** @param array<string, mixed> $context */
    public function execute(WorkflowSubjectReferenceInterface $subject, WorkflowInstance $instance, TransitionDefinition $transition, array $context): SideEffectResult
    {
        EngineFixtures::$flakyRuns++;
        if (EngineFixtures::$flakyFailuresLeft > 0) {
            EngineFixtures::$flakyFailuresLeft--;

            return SideEffectResult::failure('busy', 'Try later');
        }

        return SideEffectResult::success();
    }
}

final class EngineApprovalGuard implements WorkflowGuardInterface
{
    public function evaluate(WorkflowSubjectReferenceInterface $subject, WorkflowInstance $instance, TransitionDefinition $transition, array $context): GuardResult
    {
        return EngineFixtures::$approved ? GuardResult::pass() : GuardResult::deny('not_approved', 'Awaiting approval.');
    }
}

final class EngineReceiptSideEffect implements WorkflowSideEffectInterface
{
    public function execute(WorkflowSubjectReferenceInterface $subject, WorkflowInstance $instance, TransitionDefinition $transition, array $context): SideEffectResult
    {
        EngineFixtures::$sideEffectRuns++;

        return SideEffectResult::failure('mailer_down', 'SMTP refused');
    }
}

final class EngineContainer implements ContainerInterface
{
    public function get(string $id): object
    {
        return new $id();
    }

    public function has(string $id): bool
    {
        return class_exists($id);
    }
}

/** Stores copies, so the engine's in-memory instance is never the stored one. */
final class EngineInstances implements WorkflowInstanceRepositoryInterface
{
    /** @var array<string, WorkflowInstance> */
    private array $rows = [];

    private int $next = 0;

    private bool $race = false;

    /** Between the engine's read and its write, another worker commits a transition. */
    public function anotherWriterCommitsBeforeTheNextWrite(): void
    {
        $this->race = true;
    }

    public function findById(string $id): ?WorkflowInstance
    {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function findBySubject(string $workflowKey, string $subjectType, string $subjectId): ?WorkflowInstance
    {
        foreach ($this->rows as $row) {
            if ([$row->getWorkflowKey(), $row->getSubjectType(), $row->getSubjectId()] === [$workflowKey, $subjectType, $subjectId]) {
                return clone $row;
            }
        }

        return null;
    }

    public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array
    {
        return [];
    }

    public function save(object $entity): void
    {
        \assert($entity instanceof WorkflowInstance);
        if ($entity->getId() === '') {
            $entity->setId('wf-' . ++$this->next);
        }
        $this->rows[$entity->getId()] = clone $entity;
    }

    public function saveWithVersionCheck(WorkflowInstance $instance, int $expectedVersion): bool
    {
        if ($this->race) {
            $this->race = false;
            $this->rows[$instance->getId()]->setVersion($this->rows[$instance->getId()]->getVersion() + 1);
        }
        if (($this->rows[$instance->getId()] ?? null)?->getVersion() !== $expectedVersion) {
            return false;
        }
        $this->rows[$instance->getId()] = clone $instance;

        return true;
    }
}

final class EngineHistory implements WorkflowTransitionHistoryRepositoryInterface
{
    /** @var list<WorkflowTransitionHistory> */
    private array $saved = [];

    /** @return list<WorkflowTransitionHistory> */
    public function all(): array
    {
        return $this->saved;
    }

    /** @return list<array{string, string, ?string, string, int}> transition, from, to, result, attempt */
    public function rows(): array
    {
        return array_map(static fn (WorkflowTransitionHistory $h): array => [$h->getTransitionKey(), $h->getFromState(), $h->getToState(), $h->getResult(), $h->getAttempt()], $this->saved);
    }

    public function save(object $entity): void
    {
        \assert($entity instanceof WorkflowTransitionHistory);
        $this->saved[] = $entity;
    }

    public function findByInstanceId(string $instanceId, int $limit = 100): array
    {
        return $this->saved;
    }

    public function countAttempts(string $instanceId, string $transitionKey): int
    {
        return count(array_filter($this->saved, static fn (WorkflowTransitionHistory $h): bool => $h->getWorkflowInstanceId() === $instanceId && $h->getTransitionKey() === $transitionKey));
    }
}

final class EngineEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    /** @return list<class-string> */
    public function classes(): array
    {
        return array_map(static fn (object $e): string => $e::class, $this->dispatched);
    }

    public function create(string $eventClass, array $payload): object
    {
        return new $eventClass();
    }

    public function dispatch(object $event): void
    {
        $this->dispatched[] = $event;
    }

    public function addPostDispatchHook(callable $hook): void
    {
    }
}

final class EngineScheduler implements SchedulerInterface
{
    /** @var list<array{jobClass: string, runAt: \DateTimeImmutable, payload: array<string, mixed>, lockKey: ?string}> */
    public array $jobs = [];

    public function dispatchAt(string $jobClass, \DateTimeImmutable $runAt, array $payload = [], ?string $pool = null, ?string $tenantId = null, ?string $lockKey = null, int $maxAttempts = 1, int $retryBackoffSeconds = 0): string
    {
        $this->jobs[] = ['jobClass' => $jobClass, 'runAt' => $runAt, 'payload' => $payload, 'lockKey' => $lockKey];

        return 'job-' . count($this->jobs);
    }

    public function dispatchAfter(string $jobClass, \DateInterval $delay, array $payload = [], ?string $pool = null, ?string $tenantId = null, ?string $lockKey = null, int $maxAttempts = 1, int $retryBackoffSeconds = 0): string
    {
        return $this->dispatchAt($jobClass, (new \DateTimeImmutable())->add($delay), $payload, $pool, $tenantId, $lockKey);
    }
}

final class EngineLog implements LoggerInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $warnings = [];

    /** @var list<array{string, array<string, mixed>}> */
    public array $errors = [];

    public function error(string $message, array $context = []): void { $this->errors[] = [$message, $context]; }
    public function critical(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
}
