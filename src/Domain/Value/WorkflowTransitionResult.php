<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Value;

use Semitexa\Workflow\Enum\TransitionResultEnum;

final readonly class WorkflowTransitionResult
{
    private function __construct(
        public string $instanceId,
        public string $transitionKey,
        public TransitionResultEnum $result,
        public string $fromState,
        public ?string $toState,
        public ?string $failureCode,
        public ?string $failureMessage,
        /** @var list<array{guard: string, code: string, message: string}> */
        public array $guardFailures,
        public bool $scheduledFollowUp,
    ) {}

    public static function applied(
        string $instanceId,
        string $transitionKey,
        string $fromState,
        string $toState,
        bool $scheduledFollowUp = false,
    ): self {
        return new self(
            instanceId: $instanceId,
            transitionKey: $transitionKey,
            result: TransitionResultEnum::Applied,
            fromState: $fromState,
            toState: $toState,
            failureCode: null,
            failureMessage: null,
            guardFailures: [],
            scheduledFollowUp: $scheduledFollowUp,
        );
    }

    public static function rejectedGuard(
        string $instanceId,
        string $transitionKey,
        string $fromState,
        array $guardFailures,
    ): self {
        return new self(
            instanceId: $instanceId,
            transitionKey: $transitionKey,
            result: TransitionResultEnum::RejectedGuard,
            fromState: $fromState,
            toState: null,
            failureCode: 'guard_denied',
            failureMessage: 'One or more guards denied the transition.',
            guardFailures: $guardFailures,
            scheduledFollowUp: false,
        );
    }

    public static function rejectedInvalid(
        string $instanceId,
        string $transitionKey,
        string $fromState,
        string $failureCode,
        string $failureMessage,
    ): self {
        return new self(
            instanceId: $instanceId,
            transitionKey: $transitionKey,
            result: TransitionResultEnum::RejectedInvalid,
            fromState: $fromState,
            toState: null,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            guardFailures: [],
            scheduledFollowUp: false,
        );
    }

    public static function rejectedConflict(
        string $instanceId,
        string $transitionKey,
        string $fromState,
    ): self {
        return new self(
            instanceId: $instanceId,
            transitionKey: $transitionKey,
            result: TransitionResultEnum::RejectedConflict,
            fromState: $fromState,
            toState: null,
            failureCode: 'version_conflict',
            failureMessage: 'Concurrent modification detected. Retry the transition.',
            guardFailures: [],
            scheduledFollowUp: false,
        );
    }

    public function isApplied(): bool
    {
        return $this->result->isApplied();
    }
}
