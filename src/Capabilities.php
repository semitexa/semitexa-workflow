<?php

declare(strict_types=1);

namespace Semitexa\Workflow;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'workflow.state-machine',
    summary: 'Business processes as code-defined state machines with guarded transitions, side effects and history.',
    useWhen: 'An entity moves through named states and the legal moves between them are a rule worth enforcing in one place.',
    avoidWhen: 'A boolean flag covers it. Two states are not a workflow, and the machinery would outweigh the problem.',
    replaces: [
        'a status column plus if-statements scattered across handlers',
        'a hand-rolled transition table with no history and no guard on illegal moves',
    ],
)]
final class Capabilities
{
}
