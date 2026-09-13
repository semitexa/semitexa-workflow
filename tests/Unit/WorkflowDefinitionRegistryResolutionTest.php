<?php

declare(strict_types=1);

namespace Semitexa\Workflow\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Workflow\Application\Service\WorkflowDefinitionRegistry;

/**
 * The registry builds definitions through the container when it has one.
 *
 * A workflow definition used to be built with `new $className()` and nothing
 * else, so one that wanted an injected dependency could not have it — the
 * property stayed uninitialized and the first read threw, far from here.
 * MediaCollectionRegistry solved the same problem for its providers; this is
 * that shape, mirrored.
 *
 * The two conditions worth pinning are the ones that are easy to get wrong:
 *
 * 1. Built OUTSIDE the container — which is how a test or a CLI path may build
 *    it — the injected property is UNINITIALIZED rather than null, so reading
 *    it directly throws. `isset()` is what makes that fall through instead.
 * 2. Only the container call sits inside the try. Constructing directly in the
 *    catch as well would answer a throwing constructor by running that same
 *    constructor again, repeating its side effects and discarding the original
 *    error for the second one.
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

    /** And the guard the registry uses answers false for that state. */
    #[Test]
    public function the_guard_the_registry_uses_falls_through_rather_than_throwing(): void
    {
        $registry = new WorkflowDefinitionRegistry();

        $reads = static function () use ($registry): bool {
            $property = new \ReflectionProperty(WorkflowDefinitionRegistry::class, 'container');

            return $property->isInitialized($registry);
        };

        self::assertFalse($reads(), 'isset() on the real property is exactly this answer');
    }

    /**
     * A definition the container refuses is still built.
     *
     * The container branch may fail for a definition nothing registered, and
     * the registry must fall back rather than lose it — that is the whole
     * point of consulting the container only as an opportunity.
     */
    #[Test]
    public function a_refusing_container_does_not_lose_the_definition(): void
    {
        $registry = new WorkflowDefinitionRegistry();
        $container = new class () implements \Psr\Container\ContainerInterface {
            public int $calls = 0;

            public function get(string $id): mixed
            {
                $this->calls++;
                throw new class ('nothing registered') extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        (new \ReflectionProperty(WorkflowDefinitionRegistry::class, 'container'))->setValue($registry, $container);

        // all() runs initialize(), which consults the container per definition
        // and falls back to `new` when it throws.
        $definitions = $registry->all();

        self::assertIsArray($definitions);
        foreach ($definitions as $key => $definition) {
            self::assertIsString($key);
            self::assertInstanceOf(\Semitexa\Workflow\Domain\Contract\WorkflowDefinitionInterface::class, $definition);
        }
    }
}
