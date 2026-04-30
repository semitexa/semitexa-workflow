<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Workflow\Application\Db\MySQL\Model\WorkflowTransitionHistoryResourceModel;
use Semitexa\Workflow\Domain\Contract\WorkflowTransitionHistoryRepositoryInterface;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

#[SatisfiesRepositoryContract(of: WorkflowTransitionHistoryRepositoryInterface::class)]
final class WorkflowTransitionHistoryRepository implements WorkflowTransitionHistoryRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function save(object $entity): void
    {
        if (!$entity instanceof WorkflowTransitionHistory) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %s, got %s.',
                WorkflowTransitionHistory::class,
                $entity::class,
            ));
        }

        $persisted = $entity->id === ''
            ? $this->repository()->insert($entity)
            : $this->repository()->update($entity);

        $this->copyIntoMutableDomain($persisted, $entity);
    }

    public function findByInstanceId(string $instanceId, int $limit = 100): array
    {
        /** @var list<WorkflowTransitionHistory> */
        return $this->repository()->query()
            ->where(WorkflowTransitionHistoryResourceModel::column('workflowInstanceId'), Operator::Equals, $instanceId)
            ->orderBy(WorkflowTransitionHistoryResourceModel::column('createdAt'), Direction::Asc)
            ->limit($limit)
            ->fetchAllAs(WorkflowTransitionHistory::class, $this->orm()->getMapperRegistry());
    }

    public function countAttempts(string $instanceId, string $transitionKey): int
    {
        $result = $this->adapter()->execute(
            'SELECT COUNT(*) as cnt FROM workflow_transition_history WHERE workflow_instance_id = :id AND transition_key = :tk',
            ['id' => \Semitexa\Orm\Uuid\Uuid7::toBytes($instanceId), 'tk' => $transitionKey],
        );

        return (int) ($result->rows[0]['cnt'] ?? 0);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WorkflowTransitionHistoryResourceModel::class,
            WorkflowTransitionHistory::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }

    private function copyIntoMutableDomain(object $source, WorkflowTransitionHistory $target): void
    {
        $source instanceof WorkflowTransitionHistory || throw new \InvalidArgumentException('Unexpected persisted domain model.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
