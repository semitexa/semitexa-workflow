<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Enum;

enum WorkflowStatus: string
{
    case Active = 'active';
    case Waiting = 'waiting';
    case AwaitingManualAction = 'awaiting_manual_action';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Cancelled => true,
            default => false,
        };
    }

    public function isRunnable(): bool
    {
        return match ($this) {
            self::Active,
            self::Waiting,
            self::AwaitingManualAction => true,
            default => false,
        };
    }
}
