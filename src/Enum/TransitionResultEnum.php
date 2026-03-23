<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Enum;

enum TransitionResultEnum: string
{
    case Applied = 'applied';
    case RejectedGuard = 'rejected_guard';
    case RejectedInvalid = 'rejected_invalid';
    case RejectedConflict = 'rejected_conflict';

    public function isApplied(): bool
    {
        return $this === self::Applied;
    }

    public function isRejected(): bool
    {
        return $this !== self::Applied;
    }
}
