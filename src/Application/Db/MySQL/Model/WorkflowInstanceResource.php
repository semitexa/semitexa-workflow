<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Workflow\Domain\Model\WorkflowInstance;

#[FromTable(name: 'workflow_instances', mapTo: WorkflowInstance::class)]
#[Index(columns: ['workflow_key', 'subject_type', 'subject_id'], unique: true, name: 'uniq_workflow_instance_subject')]
#[Index(columns: ['tenant_id', 'status', 'waiting_until'], name: 'idx_workflow_instances_tenant_status_waiting')]
#[Index(columns: ['workflow_key', 'current_state'], name: 'idx_workflow_instances_key_state')]
#[Index(columns: ['awaiting_manual_action', 'updated_at'], name: 'idx_workflow_instances_manual_action')]
class WorkflowInstanceResource implements DomainMappable
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $workflow_key = '';

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $subject_type = '';

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $subject_id = '';

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 128)]
    public string $current_state = '';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $status = 'active';

    #[Column(type: MySqlType::Int)]
    public int $version = 0;

    #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
    public ?string $active_transition_key = null;

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $last_error_code = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $last_error_message = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $waiting_until = null;

    #[Column(type: MySqlType::TinyInt)]
    public int $awaiting_manual_action = 0;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $payload_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $context_json = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $completed_at = null;

    public function toDomain(): WorkflowInstance
    {
        $instance = new WorkflowInstance();
        $instance->id = $this->id;
        $instance->workflowKey = $this->workflow_key;
        $instance->subjectType = $this->subject_type;
        $instance->subjectId = $this->subject_id;
        $instance->tenantId = $this->tenant_id;
        $instance->currentState = $this->current_state;
        $instance->status = $this->status;
        $instance->version = $this->version;
        $instance->activeTransitionKey = $this->active_transition_key;
        $instance->lastErrorCode = $this->last_error_code;
        $instance->lastErrorMessage = $this->last_error_message;
        $instance->waitingUntil = $this->waiting_until;
        $instance->awaitingManualAction = (bool) $this->awaiting_manual_action;
        $instance->payloadJson = $this->payload_json;
        $instance->contextJson = $this->context_json;
        $instance->createdAt = $this->created_at;
        $instance->updatedAt = $this->updated_at;
        $instance->completedAt = $this->completed_at;
        return $instance;
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof WorkflowInstance);
        $r = new static();
        $r->id = $entity->id;
        $r->workflow_key = $entity->workflowKey;
        $r->subject_type = $entity->subjectType;
        $r->subject_id = $entity->subjectId;
        $r->tenant_id = $entity->tenantId;
        $r->current_state = $entity->currentState;
        $r->status = $entity->status;
        $r->version = $entity->version;
        $r->active_transition_key = $entity->activeTransitionKey;
        $r->last_error_code = $entity->lastErrorCode;
        $r->last_error_message = $entity->lastErrorMessage;
        $r->waiting_until = $entity->waitingUntil;
        $r->awaiting_manual_action = $entity->awaitingManualAction ? 1 : 0;
        $r->payload_json = $entity->payloadJson;
        $r->context_json = $entity->contextJson;
        $r->created_at = $entity->createdAt;
        $r->updated_at = $entity->updatedAt;
        $r->completed_at = $entity->completedAt;
        return $r;
    }
}
