<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Enum;

enum TriggerType: string
{
    /** Initiated by a handler or operator action */
    case Manual = 'manual';

    /** Initiated after a domain event is observed */
    case Event = 'event';

    /** Initiated by a scheduler job or timeout check */
    case Scheduled = 'scheduled';

    /** Initiated directly by workflow side-effects after a successful transition */
    case Internal = 'internal';
}
