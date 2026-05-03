<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Domain\Contract;

use Semitexa\Workflow\Domain\Model\TransitionDefinition;

/**
 * Defines a code-first workflow state machine.
 *
 * Classes implementing this interface and marked with #[AsWorkflowDefinition]
 * are discovered automatically by WorkflowDefinitionRegistry.
 *
 * The authoritative source of workflow behavior stays in PHP, not in the database.
 */
interface WorkflowDefinitionInterface
{
    /**
     * Unique key identifying this workflow type.
     * Used to look up the definition and stored on all workflow instances.
     */
    public static function key(): string;

    /** The state that every new workflow instance starts in. */
    public function initialState(): string;

    /**
     * All valid states for this workflow.
     * @return list<string>
     */
    public function states(): array;

    /**
     * All defined transitions.
     * @return list<TransitionDefinition>
     */
    public function transitions(): array;

    /**
     * States that represent a terminal (no-further-transitions) condition.
     * @return list<string>
     */
    public function terminalStates(): array;
}
