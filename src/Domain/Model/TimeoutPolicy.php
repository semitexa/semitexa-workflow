<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Model;

final readonly class TimeoutPolicy
{
    public function __construct(
        /** Seconds after entering the state before the timeout transition fires */
        public int $afterSeconds,
        /** The transition key to apply on timeout */
        public string $transitionKey,
        /** Pool to use for the timeout scheduler job */
        public string $pool = 'default',
    ) {
        // The pool becomes an argument of its own scheduler:work, and the
        // worker container takes one plain word there (core's WorkerProcess
        // rule). A pool like 'slow lane' was accepted here, its worker was
        // left out with a warning, and its timeouts were dispatched to a pool
        // nothing reads. A leading '-' made it an option of scheduler:work,
        // and \z, not $, which also matched before a final newline.
        if (preg_match('/^[A-Za-z0-9._:\/=][A-Za-z0-9._:\/=-]*\z/', $pool) !== 1) {
            throw new \InvalidArgumentException("A timeout pool must be one plain word (letters, digits and . _ : / = -, not starting with -), got '{$pool}': scheduler:work cannot be started for it.");
        }
    }
}
