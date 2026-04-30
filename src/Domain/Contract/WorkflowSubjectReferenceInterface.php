<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Contract;

/**
 * Identifies the domain subject of a workflow instance.
 *
 * Implement this interface on domain entities or value objects that
 * can be used as workflow subjects. This avoids coupling the workflow
 * package to a specific ORM entity base class.
 */
interface WorkflowSubjectReferenceInterface
{
    public function workflowSubjectType(): string;

    public function workflowSubjectId(): string;

    public function workflowTenantId(): ?string;
}
