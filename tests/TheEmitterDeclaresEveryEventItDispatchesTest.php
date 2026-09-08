<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\EventStore\InMemoryEventStore;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Orchestrator\Event\OrchestratorEvents;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Workflow\Entities\StateDefinition;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier for greenhouse decisions/0228 in this package: what this package DISPATCHES is
 * exactly what it DECLARES. A spy dispatcher that implements both {@see MilpaEventDispatcherInterface}
 * and {@see DeclaredEvents} is handed to the REAL code path — a one-node process advanced to its
 * terminal state by {@see ProcessRunner} — and afterwards it must hold no dispatched name without a
 * declaration, the exact expected list of names (so a deleted or renamed declaration goes red), and
 * a subject key/type per declaration that matches the payload the spy really saw. The control is
 * the same code path with a dispatcher that cannot be declared to: it runs, and still dispatches.
 */
final class TheEmitterDeclaresEveryEventItDispatchesTest extends TestCase
{
    /**
     * Retyped here on purpose, not read from {@see OrchestratorEvents}: the list this test believes
     * in must not move when the declarations do, or the test could never disagree with them.
     */
    private const array EXPECTED_NAMES = ['process.terminal'];

    public function testEveryNameTheSpySawDispatchedWasDeclaredToIt(): void
    {
        $spy = $this->spy();
        $this->runOneNodeProcessToTerminal($spy);

        $this->assertNotSame([], $spy->dispatched(), 'the code path must dispatch at least once, or this proves nothing');
        $declaredNames = $this->namesOf($spy->declared());
        foreach ($spy->dispatched() as $name) {
            $this->assertContains($name, $declaredNames, sprintf('«%s» was dispatched but never declared', $name));
        }
    }

    public function testTheDeclaredSetIsExactlyTheExpectedList(): void
    {
        $spy = $this->spy();
        $this->runOneNodeProcessToTerminal($spy);

        $this->assertSame(self::EXPECTED_NAMES, $this->namesOf($spy->declared()));
        $this->assertSame(self::EXPECTED_NAMES, $spy->dispatched());
        $this->assertSame(self::EXPECTED_NAMES, $this->namesOf(OrchestratorEvents::declarations()));
    }

    public function testEachDeclarationDescribesThePayloadTheSpyReallySaw(): void
    {
        $spy = $this->spy();
        $this->runOneNodeProcessToTerminal($spy);

        $this->assertNotSame([], $spy->declared(), 'with nothing declared there is nothing to describe, and this test would pass for free');
        foreach ($spy->declared() as $declaration) {
            $payload = $spy->payloads[$declaration->name] ?? null;
            $this->assertIsArray($payload, sprintf('«%s» is declared but the spy never saw it dispatched', $declaration->name));
            $this->assertArrayHasKey($declaration->subjectKey, $payload, sprintf('the payload of «%s» carries no «%s»', $declaration->name, $declaration->subjectKey));
            $this->assertSame($declaration->subjectType, get_debug_type($payload[$declaration->subjectKey]));
            $this->assertSame(ProcessRunner::class, $declaration->dispatchedBy);
            $this->assertFalse($declaration->mutable, 'nothing in the payload is meant to be changed in place');
            $this->assertFalse($declaration->interceptable);
            $this->assertArrayNotHasKey('slot', $payload, 'an interceptable event would carry a slot — this one does not');
        }
    }

    public function testDeclaringTwiceKeepsOneDeclarationPerName(): void
    {
        $spy = $this->spy();
        new ProcessRunner($spy);
        new ProcessRunner($spy);

        $this->assertSame(self::EXPECTED_NAMES, $this->namesOf($spy->declared()));
    }

    public function testControlADispatcherThatCannotBeDeclaredToStillRunsTheProcess(): void
    {
        $plain = new class () implements MilpaEventDispatcherInterface {
            /** @var list<string> */
            public array $dispatches = [];

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->dispatches[] = $eventName;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };

        $this->runOneNodeProcessToTerminal($plain);

        $this->assertSame(self::EXPECTED_NAMES, $plain->dispatches, 'a dispatcher that is asked nothing still receives every dispatch');
    }

    /**
     * Drives the real code path: a process whose only state is both initial and terminal is
     * started on a fresh store and advanced by a {@see ProcessRunner} built over `$dispatcher` —
     * the runner finds it terminal at once and fires the terminal seam exactly once.
     */
    private function runOneNodeProcessToTerminal(MilpaEventDispatcherInterface $dispatcher): void
    {
        $only = (new StateDefinition())
            ->setDomain('one_node')
            ->setCode('done')
            ->setLabel('Done')
            ->setSortOrder(0)
            ->setIsInitial(true)
            ->setIsTerminal(true);

        $store = new InMemoryEventStore();
        $instance = ProcessInstance::start($store, new ProcessDefinition([$only], []), ['ref' => 1]);

        (new ProcessRunner($dispatcher))->advance($store, $instance, new HumanGate(new StubDecisionSurfaceFactory()), 'human:tester');
    }

    /**
     * A dispatcher that remembers what was declared to it and what it was asked to dispatch, with
     * the payload of each name — and nothing else: no subscribers, no delivery.
     */
    private function spy(): MilpaEventDispatcherInterface&DeclaredEvents
    {
        return new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var list<EventDeclaration> */
            private array $declared = [];

            /** @var list<string> */
            private array $dispatched = [];

            /** @var array<string, array<string, mixed>> */
            public array $payloads = [];

            public function declare(EventDeclaration ...$events): void
            {
                foreach ($events as $event) {
                    foreach ($this->declared as $known) {
                        if ($known->name === $event->name) {
                            continue 2;
                        }
                    }
                    $this->declared[] = $event;
                }
            }

            public function declared(): array
            {
                return $this->declared;
            }

            public function dispatched(): array
            {
                return $this->dispatched;
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                if (!\in_array($eventName, $this->dispatched, true)) {
                    $this->dispatched[] = $eventName;
                }
                $this->payloads[$eventName] = $payload;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };
    }

    /**
     * @param list<EventDeclaration> $declarations
     *
     * @return list<string>
     */
    private function namesOf(array $declarations): array
    {
        return array_map(static fn (EventDeclaration $declaration): string => $declaration->name, $declarations);
    }
}
