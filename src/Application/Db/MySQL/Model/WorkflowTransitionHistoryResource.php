<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Trait\HasUuidV7;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Workflow\Domain\Model\WorkflowTransitionHistory;

#[FromTable(name: 'workflow_transition_history', mapTo: WorkflowTransitionHistory::class)]
#[Index(columns: ['workflow_instance_id', 'created_at'], name: 'idx_workflow_history_instance_created')]
#[Index(columns: ['transition_key', 'created_at'], name: 'idx_workflow_history_transition_created')]
#[Index(columns: ['result', 'created_at'], name: 'idx_workflow_history_result_created')]
class WorkflowTransitionHistoryResource implements DomainMappable
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

    public function toDomain(): WorkflowTransitionHistory
    {
        $h = new WorkflowTransitionHistory();
        $h->id = $this->id;
        $h->workflowInstanceId = $this->normalizeUuid($this->workflow_instance_id);
        $h->transitionKey = $this->transition_key;
        $h->fromState = $this->from_state;
        $h->toState = $this->to_state;
        $h->triggerType = $this->trigger_type;
        $h->triggeredByType = $this->triggered_by_type;
        $h->triggeredById = $this->triggered_by_id;
        $h->attempt = $this->attempt;
        $h->result = $this->result;
        $h->guardFailuresJson = $this->guard_failures_json;
        $h->sideEffectFailuresJson = $this->side_effect_failures_json;
        $h->metadataJson = $this->metadata_json;
        $h->createdAt = $this->created_at;
        return $h;
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof WorkflowTransitionHistory);
        $r = new static();
        $r->id = $entity->id;
        $r->workflow_instance_id = static::uuidToBytes($entity->workflowInstanceId);
        $r->transition_key = $entity->transitionKey;
        $r->from_state = $entity->fromState;
        $r->to_state = $entity->toState;
        $r->trigger_type = $entity->triggerType;
        $r->triggered_by_type = $entity->triggeredByType;
        $r->triggered_by_id = $entity->triggeredById;
        $r->attempt = $entity->attempt;
        $r->result = $entity->result;
        $r->guard_failures_json = $entity->guardFailuresJson;
        $r->side_effect_failures_json = $entity->sideEffectFailuresJson;
        $r->metadata_json = $entity->metadataJson;
        $r->created_at = $entity->createdAt;
        return $r;
    }

    private function normalizeUuid(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) === 36) {
            return $value;
        }
        return Uuid7::fromBytes($value);
    }

    private static function uuidToBytes(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) === 36) {
            return Uuid7::toBytes($value);
        }
        return $value;
    }
}
