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

    /** @return iterable<string, array{string}> */
    public static function uniqueViolations(): iterable
    {
        // As MySQL and SQLite word it: MySQL names the index, SQLite the columns.
        yield 'mysql, new index' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'order-order-1-' for key 'workflow_instances.uniq_workflow_instance_tenant_subject'"];
        yield 'mysql, old index' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'order-order-1' for key 'workflow_instances.uniq_workflow_instance_subject'"];
        yield 'sqlite' => ['SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: workflow_instances.workflow_key, workflow_instances.subject_type, workflow_instances.subject_id, workflow_instances.tenant_key'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('uniqueViolations')]
    public function the_subject_index_refusing_the_insert_reads_as_already_exists_even_when_the_winner_is_not_visible(string $message): void
    {
        // Another tenant's instance under the old index, or a winner whose
        // row this connection cannot see yet.
        $this->expectException(WorkflowAlreadyExistsException::class);

        $this->engine(winnerExists: false, violation: $message)->start($this->command());
    }

    private function engine(bool $winnerExists, string $violation = 'Duplicate entry'): WorkflowEngine
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
        $instances = new class ($winnerExists, $violation) implements WorkflowInstanceRepositoryInterface {
            private int $lookups = 0;
            public function __construct(private bool $winnerExists, private string $violation) {}
            public function findById(string $id): ?WorkflowInstance { return null; }
            public function findBySubject(string $workflowKey, string $subjectType, string $subjectId, ?string $tenantId = null): ?WorkflowInstance
            {
                return ++$this->lookups > 1 && $this->winnerExists ? new WorkflowInstance() : null;
            }
            public function findOverdueWaiting(\DateTimeImmutable $now, int $limit = 50): array { return []; }
            public function save(object $entity): void
            {
                throw new ConstraintViolationException($this->violation, '23000', 1062);
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
