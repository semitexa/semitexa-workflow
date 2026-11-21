<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

use Semitexa\Workflow\Domain\Contract\WorkflowSubjectReferenceInterface;

/**
 * The subject an instance was started for, as guards and side effects receive it.
 *
 * $tenant is the request's resolved tenant, never a value the request sent;
 * null only in an app without tenants: an instance started with null under a
 * tenant is reachable by its id from every tenant
 * ({@see WorkflowSubjectReferenceInterface::workflowTenantId()}).
 */
final readonly class WorkflowSubjectRef implements WorkflowSubjectReferenceInterface
{
    public function __construct(
        private string $type,
        private string $id,
        private ?string $tenant,
    ) {}

    public static function of(WorkflowInstance $instance): self
    {
        return new self($instance->getSubjectType(), $instance->getSubjectId(), $instance->getTenantId());
    }

    public function workflowSubjectType(): string { return $this->type; }
    public function workflowSubjectId(): string { return $this->id; }
    public function workflowTenantId(): ?string { return $this->tenant; }
}
