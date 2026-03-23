<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Attribute;

use Attribute;

/**
 * Marks a class as a workflow definition.
 *
 * Classes with this attribute must implement WorkflowDefinitionInterface.
 * The WorkflowDefinitionRegistry discovers them automatically via ClassDiscovery.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsWorkflowDefinition {}
