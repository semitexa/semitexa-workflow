<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Command;

use Semitexa\Workflow\Contract\WorkflowSubjectReferenceInterface;

final readonly class StartWorkflowCommand
{
    public function __construct(
        public string $workflowKey,
        public WorkflowSubjectReferenceInterface $subject,
        /** Additional payload data to store on the workflow instance */
        public array $payload = [],
        /** Trigger context metadata */
        public array $context = [],
    ) {}
}
