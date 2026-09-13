<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Workflow\Application\Service\WorkflowDefinitionRegistry;
use Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface;

/**
 * The registry builds definitions through the container when it has one.
 *
 * A workflow definition used to be built with `new $className()` and nothing
 * else, so one that wanted an injected dependency could not have it — the
 * property stayed uninitialized and the first read threw, far from here.
 *
 * DISCOVERY IS STUBBED, deliberately. `ClassDiscovery::findClassesWithAttribute()`
 * skips test classes and this package ships no production definition, so a test
 * that leaned on real discovery would see an empty list, loop zero times and
 * stay green with the whole change reverted. The stub is what makes the
 * assertions mean anything.
 */
final class WorkflowDefinitionRegistryResolutionTest extends TestCase
{
    /**
     * The uninitialized-property trap, stated directly: this is what the
     * `isset()` guard exists for, and it is not the same as a null check.
     */
    #[Test]
    public function an_injected_property_is_uninitialized_not_null_outside_the_container(): void
    {
        $registry = new WorkflowDefinitionRegistry();
        $property = new \ReflectionProperty(WorkflowDefinitionRegistry::class, 'container');

        self::assertFalse(
            $property->isInitialized($registry),
            'if this were merely null, a null check would do and isset() would be noise',
        );
        self::assertTrue($property->hasType());
    }

    /** With no container at all, the definition is still built — just uninjected. */
    #[Test]
    public function without_a_container_the_definition_is_still_registered(): void
    {
        $registry = $this->registryDiscovering(InjectedWorkflowProbe::class);

        $definitions = $registry->all();

        self::assertArrayHasKey('probe.injected', $definitions);
        self::assertInstanceOf(InjectedWorkflowProbe::class, $definitions['probe.injected']);
    }

    /**
     * THE POINT OF THE CHANGE: a definition the container can produce comes
     * back as the container's instance, with its dependency injected — not as
     * a fresh `new` with the property left unset.
     */
    #[Test]
    public function a_container_resolved_definition_is_the_one_that_is_kept(): void
    {
        $resolved = new InjectedWorkflowProbe();
        $resolved->setCollaborator(new ClassDiscovery());

        $container = new class ($resolved) implements ContainerInterface {
            public int $calls = 0;

            public function __construct(private object $resolved)
            {
            }

            public function get(string $id): mixed
            {
                $this->calls++;

                return $this->resolved;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $registry = $this->registryDiscovering(InjectedWorkflowProbe::class, $container);

        $definitions = $registry->all();

        self::assertSame(1, $container->calls, 'the container must actually be consulted');
        self::assertSame($resolved, $definitions['probe.injected'], 'the container instance is the one kept');
        self::assertTrue($definitions['probe.injected']->hasCollaborator());
    }

    /**
     * A definition the container REFUSES is still built. The container is an
     * opportunity, never a requirement.
     */
    #[Test]
    public function a_refusing_container_does_not_lose_the_definition(): void
    {
        $container = new class () implements ContainerInterface {
            public int $calls = 0;

            public function get(string $id): mixed
            {
                $this->calls++;

                throw new class ('nothing registered') extends \RuntimeException implements NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $registry = $this->registryDiscovering(InjectedWorkflowProbe::class, $container);

        $definitions = $registry->all();

        self::assertSame(1, $container->calls, 'a vacuous run would leave this at 0');
        self::assertInstanceOf(InjectedWorkflowProbe::class, $definitions['probe.injected']);
        self::assertFalse($definitions['probe.injected']->hasCollaborator(), 'the fallback cannot inject');
    }

    /**
     * A container that fails while PRODUCING the service is not a refusal. The
     * error propagates instead of being answered by constructing the definition
     * anyway — which would discard the real cause and hand back an object whose
     * injected properties are unset.
     */
    #[Test]
    public function a_container_that_fails_while_producing_the_service_is_not_swallowed(): void
    {
        $container = new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('the service blew up while being produced');
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $registry = $this->registryDiscovering(InjectedWorkflowProbe::class, $container);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the service blew up while being produced');
        $registry->all();
    }

    /**
     * Build a registry whose discovery returns exactly the given classes.
     *
     * @param class-string $definitionClass
     */
    private function registryDiscovering(
        string $definitionClass,
        ?ContainerInterface $container = null,
    ): WorkflowDefinitionRegistry {
        $registry = new WorkflowDefinitionRegistry();

        $discovery = new class ($definitionClass) extends ClassDiscovery {
            /** @param class-string $definitionClass */
            public function __construct(private string $definitionClass)
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return [$this->definitionClass];
            }
        };

        (new \ReflectionProperty(WorkflowDefinitionRegistry::class, 'classDiscovery'))
            ->setValue($registry, $discovery);

        if ($container !== null) {
            (new \ReflectionProperty(WorkflowDefinitionRegistry::class, 'container'))
                ->setValue($registry, $container);
        }

        return $registry;
    }
}

/** A definition shaped like one that needs the container: an injected dependency. */
class InjectedWorkflowProbe implements WorkflowDefinitionInterface
{
    #[InjectAsReadonly]
    protected ClassDiscovery $collaborator;

    public function setCollaborator(ClassDiscovery $collaborator): void
    {
        $this->collaborator = $collaborator;
    }

    public function hasCollaborator(): bool
    {
        return isset($this->collaborator);
    }

    public static function key(): string
    {
        return 'probe.injected';
    }

    public function initialState(): string
    {
        return 'draft';
    }

    public function states(): array
    {
        return ['draft', 'done'];
    }

    public function transitions(): array
    {
        return [];
    }

    public function terminalStates(): array
    {
        return ['done'];
    }
}
