<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Workflow\Application\Db\MySQL\Repository\WorkflowInstanceRepository;
use Semitexa\Workflow\Domain\Enum\WorkflowStatus;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

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
