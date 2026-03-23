<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Command;

final readonly class ScheduleWorkflowCheckCommand
{
    public function __construct(
        public string $workflowKey,
        public string $instanceId,
        public string $transitionKey,
        public \DateTimeImmutable $runAt,
        public ?string $tenantId = null,
        public string $pool = 'default',
        public ?string $lockKey = null,
    ) {}
}
