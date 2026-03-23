<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Value;

final readonly class TimeoutPolicy
{
    public function __construct(
        /** Seconds after entering the state before the timeout transition fires */
        public int $afterSeconds,
        /** The transition key to apply on timeout */
        public string $transitionKey,
        /** Pool to use for the timeout scheduler job */
        public string $pool = 'default',
    ) {}
}
