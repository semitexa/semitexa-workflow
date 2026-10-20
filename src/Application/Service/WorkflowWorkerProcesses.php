<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\WorkerProcess;

/**
 * A scheduler:work for every pool a transition's TimeoutPolicy names: a
 * timeout is dispatched to that pool, and scheduler:work reads one pool only.
 * The default pool is the scheduler's own to name.
 */
#[AsService]
#[AsWorkerProcessSource]
final class WorkflowWorkerProcesses implements WorkerProcessSourceInterface
{
    #[InjectAsReadonly]
    protected WorkflowDefinitionRegistry $registry;

    public function workerProcesses(): array
    {
        $pools = [];
        foreach ($this->registry->all() as $definition) {
            foreach ($definition->transitions() as $transition) {
                if ($transition->timeout !== null && $transition->timeout->pool !== 'default') {
                    $pools[$transition->timeout->pool] = true;
                }
            }
        }
        ksort($pools);

        $processes = [];
        foreach (array_keys($pools) as $pool) {
            try {
                $processes[] = new WorkerProcess(['scheduler:work', (string) $pool]);
            } catch (\InvalidArgumentException $e) {
                StaticLoggerBridge::warning('workflow', "Timeout pool '{$pool}' gets no scheduler:work: " . $e->getMessage(), []);
            }
        }

        return $processes;
    }
}
