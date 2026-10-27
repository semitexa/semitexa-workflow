<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Workflow\Application\Console\Command\WorkflowBackfillTenantKeyCommand;
use Semitexa\Workflow\Application\Db\MySQL\Repository\WorkflowInstanceRepository;
use Semitexa\Workflow\Application\Db\MySQL\Repository\WorkflowTransitionHistoryRepository;
use Semitexa\Workflow\Domain\Enum\WorkflowStatus;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The subject lookup ran in the system scope with no tenant condition, so
 * tenant B asking for its order:77 got tenant A's workflow instance.
 */
final class WorkflowInstanceTenantLookupTest extends TestCase
{
    private WorkflowInstanceRepository $repo;
    private string $subjectId;

    protected function setUp(): void
    {
        try {
            $orm = ContainerFactory::get()->get(OrmManager::class);
            $orm->getAdapter()->execute('SELECT 1 FROM workflow_instances LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('workflow_instances unavailable: ' . $e->getMessage());
        }
        $this->repo = new WorkflowInstanceRepository();
        (new \ReflectionProperty(WorkflowInstanceRepository::class, 'orm'))->setValue($this->repo, $orm);
        $this->subjectId = 'tenant-lookup-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            ContainerFactory::get()->get(OrmManager::class)->getAdapter()->execute(
                'DELETE FROM workflow_instances WHERE subject_id = :s',
                ['s' => $this->subjectId],
            );
        }
    }

    #[Test]
    public function a_tenant_finds_its_own_instance_and_never_another_tenants(): void
    {
        $instance = new WorkflowInstance();
        $instance->setWorkflowKey('order');
        $instance->setSubjectType('order');
        $instance->setSubjectId($this->subjectId);
        $instance->setTenantId('tenant-a');
        $instance->setCurrentState('new');
        $instance->setStatus(WorkflowStatus::Active->value);
        $instance->setVersion(0);
        $instance->setCreatedAt(new \DateTimeImmutable());
        $instance->setUpdatedAt(new \DateTimeImmutable());
        $this->repo->save($instance);

        self::assertNotNull($this->repo->findBySubject('order', 'order', $this->subjectId, 'tenant-a'));
        self::assertNull($this->repo->findBySubject('order', 'order', $this->subjectId, 'tenant-b'), "tenant-b read tenant-a's workflow");
        self::assertNull($this->repo->findBySubject('order', 'order', $this->subjectId, null));
    }

    #[Test]
    public function the_tenant_key_is_written_with_every_instance(): void
    {
        // tenant_key carries the tenant into the unique index: tenant_id
        // could not, a unique index lets any number of NULLs through.
        $orm = ContainerFactory::get()->get(OrmManager::class);
        foreach (['tenant-a' => 'tenant-a', '' => null] as $expected => $tenant) {
            $instance = new WorkflowInstance();
            $instance->setWorkflowKey('order-' . ($tenant ?? 'global'));
            $instance->setSubjectType('order');
            $instance->setSubjectId($this->subjectId);
            $instance->setTenantId($tenant);
            $instance->setCurrentState('new');
            $instance->setStatus(WorkflowStatus::Active->value);
            $instance->setVersion(0);
            $instance->setCreatedAt(new \DateTimeImmutable());
            $instance->setUpdatedAt(new \DateTimeImmutable());
            $this->repo->save($instance);

            $row = $orm->getAdapter()->execute(
                'SELECT tenant_key FROM workflow_instances WHERE subject_id = :s AND workflow_key = :k',
                ['s' => $this->subjectId, 'k' => 'order-' . ($tenant ?? 'global')],
            )->rows[0] ?? null;
            self::assertSame((string) $expected, $row['tenant_key'] ?? null);
        }
    }

    /**
     * save() copied the persisted row back with get_object_vars() from outside
     * the model, which sees none of its private properties: the instance kept
     * id '', start() returned it so, and WorkflowStarted named no instance.
     */
    #[Test]
    public function a_saved_instance_and_history_entry_carry_the_id_their_row_got(): void
    {
        $orm = ContainerFactory::get()->get(OrmManager::class);
        $instance = new WorkflowInstance();
        $instance->setWorkflowKey('order');
        $instance->setSubjectType('order');
        $instance->setSubjectId($this->subjectId);
        $instance->setTenantId('tenant-a');
        $instance->setCurrentState('new');
        $instance->setStatus(WorkflowStatus::Active->value);
        $instance->setVersion(0);
        $instance->setCreatedAt(new \DateTimeImmutable());
        $instance->setUpdatedAt(new \DateTimeImmutable());
        $this->repo->save($instance);

        self::assertSame(
            $this->repo->findBySubject('order', 'order', $this->subjectId, 'tenant-a')?->getId(),
            $instance->getId(),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $instance->getId());

        $historyRepo = new WorkflowTransitionHistoryRepository();
        (new \ReflectionProperty(WorkflowTransitionHistoryRepository::class, 'orm'))->setValue($historyRepo, $orm);
        $history = new WorkflowTransitionHistory();
        $history->setWorkflowInstanceId($instance->getId());
        $history->setTransitionKey('pay');
        $history->setFromState('new');
        $history->setToState('paid');
        $history->setTriggerType('manual');
        $history->setAttempt(1);
        $history->setResult('applied');
        $history->setCreatedAt(new \DateTimeImmutable());
        try {
            $historyRepo->save($history);

            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $history->getId());
            self::assertSame([$history->getId()], array_map(
                static fn (WorkflowTransitionHistory $h): string => $h->getId(),
                $historyRepo->findByInstanceId($instance->getId()),
            ));
        } finally {
            $orm->getAdapter()->execute(
                'DELETE FROM workflow_transition_history WHERE workflow_instance_id = (SELECT id FROM workflow_instances WHERE subject_id = :s)',
                ['s' => $this->subjectId],
            );
        }
    }

    #[Test]
    public function the_backfill_makes_an_instance_started_with_tenant_empty_one_without_a_tenant(): void
    {
        // Started before '' meant no tenant: the lookup asks for tenant_id IS
        // NULL and missed it, and a new start for the subject took the same
        // tenant_key '' and was refused by the unique index.
        $orm = ContainerFactory::get()->get(OrmManager::class);
        $instance = new WorkflowInstance();
        $instance->setWorkflowKey('order');
        $instance->setSubjectType('order');
        $instance->setSubjectId($this->subjectId);
        $instance->setTenantId(null);
        $instance->setCurrentState('new');
        $instance->setStatus(WorkflowStatus::Active->value);
        $instance->setVersion(0);
        $instance->setCreatedAt(new \DateTimeImmutable());
        $instance->setUpdatedAt(new \DateTimeImmutable());
        $this->repo->save($instance);
        $orm->getAdapter()->execute(
            "UPDATE workflow_instances SET tenant_id = '', tenant_key = '' WHERE subject_id = :s",
            ['s' => $this->subjectId],
        );
        self::assertNull($this->repo->findBySubject('order', 'order', $this->subjectId, null), 'the legacy row, as the lookup missed it');

        $command = new WorkflowBackfillTenantKeyCommand();
        (new \ReflectionProperty(WorkflowBackfillTenantKeyCommand::class, 'orm'))->setValue($command, $orm);
        self::assertSame(0, (new CommandTester($command))->execute([]));

        self::assertSame($instance->getId(), $this->repo->findBySubject('order', 'order', $this->subjectId, null)?->getId(), 'the legacy row is found as one without a tenant');
        $row = $orm->getAdapter()->execute(
            'SELECT tenant_id, tenant_key FROM workflow_instances WHERE subject_id = :s',
            ['s' => $this->subjectId],
        )->rows;
        self::assertSame([['tenant_id' => null, 'tenant_key' => '']], $row);
    }

    #[Test]
    public function the_new_index_lets_two_tenants_share_a_subject_and_nothing_else(): void
    {
        // On a copy without the old tenant-less index, as after
        // orm:sync --allow-destructive. A name of its own per run: the
        // database is shared.
        $orm = ContainerFactory::get()->get(OrmManager::class);
        if ($orm->getDriver() !== 'mysql') {
            self::markTestSkipped('MySQL DDL (CREATE TABLE LIKE)');
        }
        $db = $orm->getAdapter();
        $probe = 'wf_index_probe_' . bin2hex(random_bytes(4));
        $db->execute("CREATE TABLE {$probe} LIKE workflow_instances");
        try {
            $indexes = array_column($db->execute("SHOW INDEX FROM {$probe}")->rows, 'Key_name');
            if (in_array('uniq_workflow_instance_subject', $indexes, true)) {
                $db->execute("ALTER TABLE {$probe} DROP INDEX uniq_workflow_instance_subject");
            }
            $insert = static fn (string $tenantKey, ?string $tenant) => $db->execute(
                "INSERT INTO {$probe} (id, workflow_key, subject_type, subject_id, tenant_id, tenant_key, current_state, status, version, awaiting_manual_action)
                 VALUES (:id, 'order', 'order', 'o-1', :t, :k, 'new', 'active', 0, 0)",
                ['id' => random_bytes(16), 't' => $tenant, 'k' => $tenantKey],
            );
            $insert('tenant-a', 'tenant-a');
            $insert('tenant-b', 'tenant-b');
            $insert('', null);

            foreach ([['tenant-a', 'tenant-a'], ['', null]] as [$key, $tenant]) {
                $refused = null;
                try {
                    $insert($key, $tenant);
                } catch (\Throwable $e) {
                    $refused = $e->getMessage();
                }
                self::assertNotNull($refused, "a second instance for one subject in '{$key}' got through");
                self::assertStringContainsString('uniq_workflow_instance_tenant_subject', $refused);
            }
        } finally {
            $db->execute("DROP TABLE IF EXISTS {$probe}");
        }
    }

    #[Test]
    public function a_state_write_inside_a_transaction_rolls_back_with_it(): void
    {
        // saveWithVersionCheck() took its own pooled connection and
        // autocommitted, so a failure after it left the state moved.
        $orm = ContainerFactory::get()->get(OrmManager::class);
        $instance = new WorkflowInstance();
        $instance->setWorkflowKey('order');
        $instance->setSubjectType('order');
        $instance->setSubjectId($this->subjectId);
        $instance->setTenantId('tenant-a');
        $instance->setCurrentState('new');
        $instance->setStatus(WorkflowStatus::Active->value);
        $instance->setVersion(0);
        $instance->setCreatedAt(new \DateTimeImmutable());
        $instance->setUpdatedAt(new \DateTimeImmutable());
        $this->repo->save($instance);
        $instance = $this->repo->findBySubject('order', 'order', $this->subjectId, 'tenant-a');
        self::assertNotNull($instance);

        try {
            $orm->getTransactionManager()->run(function () use ($instance): void {
                $instance->setCurrentState('paid');
                $instance->setVersion(1);
                self::assertTrue($this->repo->saveWithVersionCheck($instance, 0));
                throw new \RuntimeException('the history insert failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame('new', $this->repo->findById($instance->getId())?->getCurrentState(), 'the state moved without its history');
    }
}
