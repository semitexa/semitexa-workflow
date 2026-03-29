<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'workflow_transition_history')]
#[Index(columns: ['workflow_instance_id', 'created_at'], name: 'idx_workflow_history_instance_created')]
#[Index(columns: ['transition_key', 'created_at'], name: 'idx_workflow_history_transition_created')]
#[Index(columns: ['result', 'created_at'], name: 'idx_workflow_history_result_created')]
class WorkflowTransitionHistoryResource
{
    use HasUuidV7;

    #[Column(type: MySqlType::Binary, length: 16)]
    public string $workflow_instance_id = '';

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $transition_key = '';

    #[Column(type: MySqlType::Varchar, length: 128)]
    public string $from_state = '';

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $to_state = null;

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $trigger_type = 'manual';

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $triggered_by_type = null;

    #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
    public ?string $triggered_by_id = null;

    #[Column(type: MySqlType::Int)]
    public int $attempt = 1;

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $result = 'applied';

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $guard_failures_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $side_effect_failures_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $metadata_json = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
}
