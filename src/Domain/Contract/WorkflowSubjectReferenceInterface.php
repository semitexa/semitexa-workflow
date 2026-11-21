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

    /**
     * The tenant the instance belongs to: the request's resolved tenant,
     * never a value the request sent. The engine stores what this returns
     * and does not compare it with the current tenant. Null (or '') means no
     * tenant, for an app without tenants: an instance started so under a
     * tenant is reachable by its id from every tenant.
     */
    public function workflowTenantId(): ?string;
}
