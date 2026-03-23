<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Value;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $backoffSeconds = 60,
        public float $backoffMultiplier = 2.0,
        public int $maxBackoffSeconds = 3600,
    ) {}

    public function backoffForAttempt(int $attempt): int
    {
        $seconds = (int) ($this->backoffSeconds * ($this->backoffMultiplier ** ($attempt - 1)));
        return min($seconds, $this->maxBackoffSeconds);
    }
}
