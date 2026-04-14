<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'workflow_transition_history')]
final readonly class WorkflowTransitionHistoryResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'workflow_instance_id', type: MySqlType::Binary, length: 16)]
        public string $workflowInstanceId,

        #[Column(name: 'transition_key', type: MySqlType::Varchar, length: 191)]
        public string $transitionKey,

        #[Column(name: 'from_state', type: MySqlType::Varchar, length: 128)]
        public string $fromState,

        #[Column(name: 'to_state', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $toState,

        #[Column(name: 'trigger_type', type: MySqlType::Varchar, length: 32)]
        public string $triggerType,

        #[Column(name: 'triggered_by_type', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $triggeredByType,

        #[Column(name: 'triggered_by_id', type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $triggeredById,

        #[Column(type: MySqlType::Int)]
        public int $attempt,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $result,

        #[Column(name: 'guard_failures_json', type: MySqlType::LongText, nullable: true)]
        public ?string $guardFailuresJson,

        #[Column(name: 'side_effect_failures_json', type: MySqlType::LongText, nullable: true)]
        public ?string $sideEffectFailuresJson,

        #[Column(name: 'metadata_json', type: MySqlType::LongText, nullable: true)]
        public ?string $metadataJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,
    ) {}
}
