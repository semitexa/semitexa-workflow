<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Workflow\Application\Service\WorkflowDefinitionRegistry;
use Semitexa\Workflow\Application\Service\WorkflowEngine;
use Semitexa\Workflow\Domain\Command\StartWorkflowCommand;
use Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowInstanceRepositoryInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;
use Semitexa\Workflow\Domain\Contract\WorkflowTransitionHistoryRepositoryInterface;
use Semitexa\Workflow\Domain\Exception\WorkflowAlreadyExistsException;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

/**
 * Two starts for one subject can both pass the existence check; the unique
 * index lets one insert through. The loser must get the typed "already
 * exists" answer, not a raw constraint violation.
 */
final class WorkflowStartRaceTest extends TestCase
{
    #[Test]
    public function the_start_that_loses_the_insert_race_reports_that_the_workflow_exists(): void
    {
        $this->expectException(WorkflowAlreadyExistsException::class);

        $this->engine(winnerExists: true)->start($this->command());
    }

    #[Test]
    public function a_constraint_violation_that_is_not_the_race_is_not_disguised(): void
    {
        $this->expectException(ConstraintViolationException::class);

        $this->engine(winnerExists: false)->start($this->command());
    }

    private function engine(bool $winnerExists): WorkflowEngine
    {
        $definition = new class implements WorkflowDefinitionInterface {
            public static function key(): string { return 'order'; }
            public function initialState(): string { return 'new'; }
            public function states(): array { return ['new']; }
            public function transitions(): array { return []; }
            public function terminalStates(): array { return []; }
        };
        $registry = (new \ReflectionClass(WorkflowDefinitionRegistry::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($registry, 'definitions'))->setValue($registry, ['order' => $definition]);
        (new \ReflectionProperty($registry, 'initialized'))->setValue($registry, true);

        // The lookup before the insert finds nothing (both racers get here);
        // the one after the failed insert sees the winner's row, or not.
        $instances = new class ($winnerExists) implements WorkflowInstanceRepositoryInterface {
            private int $lookups = 0;
            public function __construct(private bool $winnerExists) {}
            public function findById(string $id): ?WorkflowInstance { return null; }
            public function findBySubject(string $workflowKey, string $subjectType, string $subjectId): ?WorkflowInstance
            {
                return ++$this->lookups > 1 && $this->winnerExists ? new WorkflowInstance() : null;
            }
            public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array { return []; }
            public function save(object $entity): void
            {
                throw new ConstraintViolationException('Duplicate entry', '23000', 1062);
            }
            public function saveWithVersionCheck(WorkflowInstance $instance, int $expectedVersion): bool { return false; }
        };

        $engine = (new \ReflectionClass(WorkflowEngine::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($engine, 'registry'))->setValue($engine, $registry);
        (new \ReflectionProperty($engine, 'instanceRepo'))->setValue($engine, $instances);
        (new \ReflectionProperty($engine, 'historyRepo'))->setValue($engine, $this->createStub(WorkflowTransitionHistoryRepositoryInterface::class));

        return $engine;
    }

    private function command(): StartWorkflowCommand
    {
        return new StartWorkflowCommand('order', new class implements WorkflowSubjectReferenceInterface {
            public function workflowSubjectType(): string { return 'order'; }
            public function workflowSubjectId(): string { return 'order-1'; }
            public function workflowTenantId(): ?string { return null; }
        });
    }
}
