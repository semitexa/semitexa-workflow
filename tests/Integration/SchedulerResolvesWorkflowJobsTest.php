<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Workflow\Application\Service\WorkflowSideEffectRetryJob;
use Semitexa\Workflow\Application\Service\WorkflowTimeoutJob;
use Semitexa\Workflow\Domain\Contract\WorkflowEngineInterface;

/**
 * REGRESSION (release review, tk-workflow-retry-policy-applied). The scheduler
 * builds a job from the class name stored on the run, through the container
 * (RunExecutor), and the container builds only registered services. Neither
 * workflow job was one: a scheduled timeout transition, and every side-effect
 * retry, failed with "not found" and never ran. The unit tests built the jobs
 * with `new`, so they could not see it.
 */
final class SchedulerResolvesWorkflowJobsTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function jobs(): iterable
    {
        yield 'timeout' => [WorkflowTimeoutJob::class];
        yield 'side-effect retry' => [WorkflowSideEffectRetryJob::class];
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('jobs')]
    public function the_scheduler_can_build_the_job_with_its_engine(string $class): void
    {
        $job = ContainerFactory::get()->get($class);

        self::assertInstanceOf($class, $job);
        self::assertInstanceOf(WorkflowEngineInterface::class, (new \ReflectionProperty($class, 'engine'))->getValue($job));
    }
}
