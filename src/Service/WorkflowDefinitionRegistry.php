<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Service;

use Semitexa\Core\Attributes\AsService;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Workflow\Attribute\AsWorkflowDefinition;
use Semitexa\Workflow\Contract\WorkflowDefinitionInterface;
use Semitexa\Workflow\Domain\Exception\WorkflowDefinitionNotFoundException;

/**
 * Discovers and holds all workflow definitions.
 *
 * Definitions are discovered via ClassDiscovery by looking for classes
 * with the #[AsWorkflowDefinition] attribute that implement WorkflowDefinitionInterface.
 *
 * Results are cached after first initialization.
 */
#[AsService]
final class WorkflowDefinitionRegistry
{
    /** @var array<string, WorkflowDefinitionInterface> keyed by workflow key */
    private array $definitions = [];
    private bool $initialized = false;

    public function get(string $key): WorkflowDefinitionInterface
    {
        $this->initialize();
        if (!isset($this->definitions[$key])) {
            throw new WorkflowDefinitionNotFoundException($key);
        }
        return $this->definitions[$key];
    }

    public function has(string $key): bool
    {
        $this->initialize();
        return isset($this->definitions[$key]);
    }

    /**
     * @return array<string, WorkflowDefinitionInterface>
     */
    public function all(): array
    {
        $this->initialize();
        return $this->definitions;
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $classes = ClassDiscovery::findClassesWithAttribute(AsWorkflowDefinition::class);

        foreach ($classes as $className) {
            if (!is_subclass_of($className, WorkflowDefinitionInterface::class)) {
                continue;
            }

            /** @var WorkflowDefinitionInterface $definition */
            $definition = new $className();
            $key = $className::key();
            $this->definitions[$key] = $definition;
        }

        $this->initialized = true;
    }
}
