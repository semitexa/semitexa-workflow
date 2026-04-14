<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'workflow_instances')]
final readonly class WorkflowInstanceResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'workflow_key', type: MySqlType::Varchar, length: 191)]
        public string $workflowKey,

        #[Column(name: 'subject_type', type: MySqlType::Varchar, length: 191)]
        public string $subjectType,

        #[Column(name: 'subject_id', type: MySqlType::Varchar, length: 191)]
        public string $subjectId,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(name: 'current_state', type: MySqlType::Varchar, length: 128)]
        public string $currentState,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(type: MySqlType::Int)]
        public int $version,

        #[Column(name: 'active_transition_key', type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $activeTransitionKey,

        #[Column(name: 'last_error_code', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $lastErrorCode,

        #[Column(name: 'last_error_message', type: MySqlType::LongText, nullable: true)]
        public ?string $lastErrorMessage,

        #[Column(name: 'waiting_until', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $waitingUntil,

        #[Column(name: 'awaiting_manual_action', type: MySqlType::Boolean)]
        public bool $awaitingManualAction,

        #[Column(name: 'payload_json', type: MySqlType::LongText, nullable: true)]
        public ?string $payloadJson,

        #[Column(name: 'context_json', type: MySqlType::LongText, nullable: true)]
        public ?string $contextJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,

        #[Column(name: 'completed_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $completedAt,
    ) {}
}
