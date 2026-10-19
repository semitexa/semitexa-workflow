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
    }

    #[Test]
    public function a_started_workflow_moves_along_its_transitions_and_records_each(): void
    {
        $engine = $this->engine();
        $order = $engine->start($this->startOrder());

        $result = $engine->apply($this->command($order, 'pay'));

        self::assertSame(['new', 'active'], [$order->getCurrentState(), 'active'], 'started in the initial state');
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

    private function command(WorkflowInstance $instance, string $transition): ApplyTransitionCommand
    {
        return new ApplyTransitionCommand('order', $instance->getId(), $transition, TriggerType::Manual, 'user', 'u-1');
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
        ];
    }
}

final class EngineFixtures
{
    public static bool $approved = true;

    public static int $sideEffectRuns = 0;
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

    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
}
