<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'workflow_instances')]
#[Index(columns: ['workflow_key', 'subject_type', 'subject_id'], unique: true, name: 'uniq_workflow_instance_subject')]
#[Index(columns: ['tenant_id', 'status', 'waiting_until'], name: 'idx_workflow_instances_tenant_status_waiting')]
#[Index(columns: ['workflow_key', 'current_state'], name: 'idx_workflow_instances_key_state')]
#[Index(columns: ['awaiting_manual_action', 'updated_at'], name: 'idx_workflow_instances_manual_action')]
class WorkflowInstanceResource
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
}
