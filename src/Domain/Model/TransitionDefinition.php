<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

use Semitexa\Workflow\Domain\Value\RetryPolicy;
use Semitexa\Workflow\Domain\Value\TimeoutPolicy;
use Semitexa\Workflow\Enum\TriggerType;

final readonly class TransitionDefinition
{
    /**
     * @param list<string> $fromStates
     * @param list<class-string> $guards
     * @param list<class-string> $sideEffects
     */
    public function __construct(
        public string $key,
        public array $fromStates,
        public string $toState,
        public TriggerType $triggerType = TriggerType::Manual,
        public array $guards = [],
        public array $sideEffects = [],
        public bool $requiresManualApproval = false,
        public ?RetryPolicy $retryPolicy = null,
        public ?TimeoutPolicy $timeout = null,
    ) {}

    public function isValidFrom(string $state): bool
    {
        return in_array($state, $this->fromStates, true);
    }
}
