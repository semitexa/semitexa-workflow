<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Workflow\Attribute\AsWorkflowDefinition;
use Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface;
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
    /**
     * Injected rather than reached for statically. A workflow definition is
     * usually a plain holder, so the container is consulted only to give one
     * that wants injected dependencies a chance to get them — the same shape
     * MediaCollectionRegistry uses for its providers.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

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

        $classes = $this->classDiscovery()->findClassesWithAttribute(AsWorkflowDefinition::class);

        foreach ($classes as $className) {
            if (!is_subclass_of($className, WorkflowDefinitionInterface::class)) {
                continue;
            }

            // The container branch is the only thing inside the try.
            // Constructing the definition directly in the catch as well would
            // answer a throwing constructor by running that same constructor
            // AGAIN — repeating whatever side effects it managed before
            // failing, and discarding the original error for the second one.
            //
            // isset(): the injected property is UNINITIALIZED, not null, when
            // this registry is built outside the container, and reading it
            // directly would throw rather than fall through.
            $definition = null;
            if (isset($this->container)) {
                try {
                    $resolved = $this->container->get($className);
                    $definition = $resolved instanceof WorkflowDefinitionInterface ? $resolved : null;
                } catch (\Throwable) {
                    $definition = null;
                }
            }

            /** @var WorkflowDefinitionInterface $definition */
            $definition ??= new $className();
            $key = $className::key();
            $this->definitions[$key] = $definition;
        }

        $this->initialized = true;
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
