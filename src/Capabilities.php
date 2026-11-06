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
    usage: <<<'USAGE'
        #[AsWorkflowDefinition]
        final class OrderWorkflow implements WorkflowDefinitionInterface
        {
            public static function key(): string { return 'order'; }
            public function initialState(): string { return 'paid'; }
            public function states(): array { return ['paid', 'shipped']; }
            public function terminalStates(): array { return ['shipped']; }
            public function transitions(): array { return [new TransitionDefinition('ship', ['paid'], 'shipped', guards: [StockReserved::class])]; }
        }

        #[AsService]   // a guard, and a side effect (sideEffects:), come from the container
        final class StockReserved implements WorkflowGuardInterface
        {
            #[InjectAsReadonly]
            protected StockStore $stock;

            public function evaluate(WorkflowSubjectReferenceInterface $subject, WorkflowInstance $instance, TransitionDefinition $transition, array $context): GuardResult
            {
                // From your own store: $context is whatever the caller passed.
                return $this->stock->isReserved($subject->workflowSubjectId()) ? GuardResult::pass() : GuardResult::deny('no_stock', 'Reserve it first.');
            }
        }

        // After bin/semitexa orm:sync, in a handler with #[InjectAsReadonly] protected WorkflowEngineInterface $workflows:
        $subject = new WorkflowSubjectRef('order', $orderId, $tenantId);   // the resolved tenant, not the request's word
        $instance = $this->workflows->start(new StartWorkflowCommand('order', $subject));   // later: findBySubject('order', $subject)
        $result = $this->workflows->apply(new ApplyTransitionCommand('order', $instance->getId(), 'ship'));
        // Silent: a refusal is not thrown. Unless $result->isApplied(), this call moved nothing: read $result->failureCode.
        // After a conflict another call may have moved it: read the instance again before deciding.
        // get() and apply() see every tenant's instances: take the id from findBySubject(), never from a request.
        USAGE,
)]
final class Capabilities
{
}
