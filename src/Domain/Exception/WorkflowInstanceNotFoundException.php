<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Exception;

final class WorkflowInstanceNotFoundException extends \RuntimeException
{
    public function __construct(string $instanceId)
    {
        parent::__construct("Workflow instance not found: '{$instanceId}'");
    }
}
