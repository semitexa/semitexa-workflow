<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Exception;

final class WorkflowDefinitionNotFoundException extends \RuntimeException
{
    public function __construct(string $workflowKey)
    {
        parent::__construct("Workflow definition not found for key: '{$workflowKey}'");
    }
}
