<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

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
        // Clamped as a float: past ~58 attempts the product overflowed the int
        // cast, came out 0, and the retries ran with no wait at all.
        $seconds = $this->backoffSeconds * ($this->backoffMultiplier ** max(0, $attempt - 1));

        return (int) max(0, min($seconds, $this->maxBackoffSeconds));
    }
}
