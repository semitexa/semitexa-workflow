<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Command;

use Semitexa\Workflow\Domain\Enum\TriggerType;

final readonly class ApplyTransitionCommand
{
    public function __construct(
        public string $workflowKey,
        public string $instanceId,
        public string $transitionKey,
        public TriggerType $triggerType = TriggerType::Manual,
        public ?string $triggeredByType = null,
        public ?string $triggeredById = null,
        /** Additional context passed to guards and side-effects */
        public array $context = [],
        /** Idempotency key for deduplication (required for scheduled/event triggers) */
        public ?string $idempotencyKey = null,
        /**
         * Apply only to the instance at this version (a conflict otherwise):
         * a timer checked the version, then the instance left its state and
         * came back, and the old timer ended the new visit.
         */
        public ?int $expectedVersion = null,
    ) {}
}
