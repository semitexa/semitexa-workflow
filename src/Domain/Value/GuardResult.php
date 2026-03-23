<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Value;

final readonly class GuardResult
{
    private function __construct(
        public bool $passed,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function pass(): self
    {
        return new self(true, null, null);
    }

    public static function deny(string $failureCode, string $failureMessage): self
    {
        return new self(false, $failureCode, $failureMessage);
    }
}
