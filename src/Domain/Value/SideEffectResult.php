<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Value;

final readonly class SideEffectResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function success(): self
    {
        return new self(true, null, null);
    }

    public static function failure(string $failureCode, string $failureMessage): self
    {
        return new self(false, $failureCode, $failureMessage);
    }
}
