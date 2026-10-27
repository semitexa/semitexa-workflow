<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Workflow\Application\Service\WorkflowDefinitionRegistry;
use Semitexa\Workflow\Application\Service\WorkflowWorkerProcesses;
use Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface;
use Semitexa\Workflow\Domain\Model\TimeoutPolicy;
use Semitexa\Workflow\Domain\Model\TransitionDefinition;

/**
 * A timeout goes to the pool its TimeoutPolicy names, and scheduler:work reads
 * one pool: a pool other than the default one gets its own worker
 * (tk-worker-runs-mail-work, review).
 */
final class WorkflowWorkerProcessesTest extends TestCase
{
    #[Test]
    public function every_timeout_pool_but_the_default_gets_a_scheduler_worker(): void
    {
        $registry = (new \ReflectionClass(WorkflowDefinitionRegistry::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($registry, 'definitions'))->setValue($registry, ['quote' => new PoolsQuoteWorkflow()]);
        (new \ReflectionProperty($registry, 'initialized'))->setValue($registry, true);
        $source = new WorkflowWorkerProcesses();
        (new \ReflectionProperty($source, 'registry'))->setValue($source, $registry);

        $lines = array_map(static fn (WorkerProcess $p): string => $p->line(), $source->workerProcesses());

        self::assertSame(['5 scheduler:work slow'], $lines);
    }

    #[Test]
    public function a_timeout_pool_no_worker_can_be_started_for_is_refused(): void
    {
        // Accepted, it got no worker (a warning said so) and its timeouts
        // were dispatched to a pool nothing reads.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("A timeout pool must be one plain word (letters, digits and . _ : / = -), got 'slow lane': scheduler:work cannot be started for it.");

        new TimeoutPolicy(3600, 'expire', pool: 'slow lane');
    }
}

final class PoolsQuoteWorkflow implements WorkflowDefinitionInterface
{
    public static function key(): string { return 'quote'; }
    public function initialState(): string { return 'new'; }
    public function states(): array { return ['new', 'quoted', 'expired', 'reminded']; }
    public function terminalStates(): array { return ['expired']; }

    public function transitions(): array
    {
        return [
            new TransitionDefinition('send', ['new'], 'quoted', timeout: new TimeoutPolicy(3600, 'expire', pool: 'slow')),
            new TransitionDefinition('remind', ['new'], 'reminded', timeout: new TimeoutPolicy(60, 'expire')),
            new TransitionDefinition('expire', ['quoted', 'reminded'], 'expired'),
        ];
    }
}
