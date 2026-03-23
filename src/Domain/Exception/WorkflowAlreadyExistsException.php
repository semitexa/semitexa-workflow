<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Exception;

final class WorkflowAlreadyExistsException extends \RuntimeException
{
    public function __construct(string $workflowKey, string $subjectType, string $subjectId)
    {
        parent::__construct(
            "A workflow instance for '{$workflowKey}' already exists for subject '{$subjectType}:{$subjectId}'"
        );
    }
}
