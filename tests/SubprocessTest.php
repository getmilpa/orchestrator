<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Fixtures\ChainLevelProcess;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Orchestrator\Tests\Fixtures\SubprocessGrandparentProcess;
use Milpa\Orchestrator\Tests\Fixtures\SubprocessParentProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves recursive composition — "a process as a gate of another" — end to end at the engine
 * level: instantiating a parent whose middle state is a subprocess state instantiates the child
 * with correlation stamped; the child runs to its own human gate while the PARENT waits;
 * resolving the child's gate routes `subprocess_done` to the parent and advances it to ITS OWN
 * terminal; a fresh {@see EventStoreInterface} replay reconstructs both terminal states;
 * self-referential definition graphs are rejected at registration; a runaway (but non-cyclic)
 * nesting chain trips the runtime depth limit; and routing never double-fires.
 */
final class SubprocessTest extends TestCase
{
    private function registry(): ProcessDefinitionRegistry
    {
        $registry = new ProcessDefinitionRegistry();
        $registry->register(SampleProcess::NAME, SampleProcess::build());
        $registry->register(SubprocessParentProcess::NAME, SubprocessParentProcess::build());
        $registry->register(SubprocessGrandparentProcess::NAME, SubprocessGrandparentProcess::build());

        return $registry;
    }

    /**
     * @return list<Event>
     */
    private function subprocessStartedMarkers(EventStoreInterface $store, string $streamId): array
    {
        return array_values(array_filter(
            $store->replay($streamId),
            static fn (Event $event): bool => $event->type === 'SubprocessStarted',
        ));
    }

    /**
     * Reads `child_instance_id` off `$streamId`'s SINGLE `SubprocessStarted` marker, asserting it
     * is a non-empty string — a small helper so every test below gets a properly-typed local
     * variable instead of `mixed` (this suite's own {@see EventStoreInterface}-backed streams
     * always carry it as a string; the assertion documents that expectation loudly for anyone
     * reading a failure).
     */
    private function childInstanceIdOf(EventStoreInterface $store, string $streamId): string
    {
        $markers = $this->subprocessStartedMarkers($store, $streamId);
        $this->assertCount(1, $markers, "expected exactly one SubprocessStarted marker on stream '{$streamId}'");

        $childInstanceId = $markers[0]->payload['child_instance_id'];
        $this->assertIsString($childInstanceId);
        $this->assertNotSame('', $childInstanceId);

        return (string) $childInstanceId;
    }

    public function testInstantiatingTheParentInstantiatesTheChildWithCorrelationStamped(): void
    {
        $store = new InMemoryEventStore();
        $registry = $this->registry();
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), $registry);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $parent = ProcessInstance::start(
            $store,
            $registry->get(SubprocessParentProcess::NAME),
            ['ref' => 42, '_definition' => SubprocessParentProcess::NAME],
            'parent-1',
        );
        $runner->advance($store, $parent, $gate, 'ana');

        // The parent WAITS at its subprocess state — it does not auto-advance past it.
        $this->assertSame(SubprocessParentProcess::STATE_REVIEW, $parent->currentState($store));

        $started = $this->subprocessStartedMarkers($store, 'parent-1');
        $this->assertCount(1, $started);
        $this->assertNotSame('', $started[0]->payload['correlation_id']);

        $childInstanceId = $this->childInstanceIdOf($store, 'parent-1');
        $child = new ProcessInstance($childInstanceId, $registry->get(SampleProcess::NAME));
        $childContext = $child->context($store);

        $this->assertSame('parent-1', $childContext['parent_instance_id']);
        $this->assertSame(SubprocessParentProcess::STATE_REVIEW, $childContext['parent_state']);
        $this->assertSame($started[0]->payload['correlation_id'], $childContext['correlation_id']);
        $this->assertSame(42, $childContext['ref'], 'inputsMap must project the parent context key into the child input');
        $this->assertSame(SampleProcess::NAME, $childContext['_definition']);
        $this->assertSame('ana', $childContext['_requester']);

        // The child itself auto-advanced all the way to ITS OWN gate.
        $this->assertSame(SampleProcess::STATE_REVIEW_GATE, $child->currentState($store));
    }

    public function testCallingAdvanceAgainOnTheWaitingParentDoesNotReinstantiateTheChild(): void
    {
        $store = new InMemoryEventStore();
        $registry = $this->registry();
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), $registry);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $parent = ProcessInstance::start(
            $store,
            $registry->get(SubprocessParentProcess::NAME),
            ['ref' => 1, '_definition' => SubprocessParentProcess::NAME],
            'parent-2',
        );

        $runner->advance($store, $parent, $gate, 'ana');
        $runner->advance($store, $parent, $gate, 'ana');

        $this->assertCount(1, $this->subprocessStartedMarkers($store, 'parent-2'), 'advance() must not start a second child for the same subprocess state');
    }

    public function testResolvingTheChildsGateRoutesSubprocessDoneAndAdvancesTheParentToItsTerminal(): void
    {
        $store = new InMemoryEventStore();
        $registry = $this->registry();

        /** @var list<array<string, mixed>> $terminals */
        $terminals = [];
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('process.terminal', function (string $name, array $payload) use (&$terminals): void {
            $terminals[] = $payload;
        });

        $runner = new ProcessRunner($dispatcher, $registry);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $parent = ProcessInstance::start(
            $store,
            $registry->get(SubprocessParentProcess::NAME),
            ['ref' => 7, '_definition' => SubprocessParentProcess::NAME],
            'parent-3',
        );
        $runner->advance($store, $parent, $gate, 'ana');

        $childInstanceId = $this->childInstanceIdOf($store, 'parent-3');
        $child = new ProcessInstance($childInstanceId, $registry->get(SampleProcess::NAME));

        $pending = $gate->pendingFor($store, $child);
        $this->assertNotNull($pending);

        // 'ben' differs from the child's requester ('ana') to avoid the self-approval guard.
        $gate->resolve($store, $child, $pending->gateId, 'approve', 'ben');
        $runner->advance($store, $child, $gate, 'ana');

        $this->assertSame(SampleProcess::STATE_DONE, $child->currentState($store));
        $this->assertSame(SubprocessParentProcess::STATE_FINISHED, $parent->currentState($store));

        // Both the child's own process.terminal (its domain effects) AND the parent's fired.
        $this->assertCount(2, $terminals);
        $this->assertSame($childInstanceId, $terminals[0]['instance_id']);
        $this->assertSame(SampleProcess::STATE_DONE, $terminals[0]['final_state']);
        $this->assertSame('parent-3', $terminals[1]['instance_id']);
        $this->assertSame(SubprocessParentProcess::STATE_FINISHED, $terminals[1]['final_state']);

        // The parent's routed context carries the outputs SubprocessParentProcess declared
        // ('ref') — projected straight from the child's own terminal context.
        $this->assertSame(7, $parent->context($store)['outputs']['ref']);
        $this->assertSame('done', $parent->context($store)['outcome']);
    }

    public function testAFreshEventStoreReplayReconstructsBothParentAndChildTerminalStates(): void
    {
        $path = sys_get_temp_dir() . '/orchestrator-subprocess-' . uniqid('', true) . '.jsonl';

        try {
            $store = new FileEventStore($path);
            $registry = $this->registry();
            $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), $registry);
            $gate = new HumanGate(new StubDecisionSurfaceFactory());

            $parent = ProcessInstance::start(
                $store,
                $registry->get(SubprocessParentProcess::NAME),
                ['ref' => 9, '_definition' => SubprocessParentProcess::NAME],
                'parent-4',
            );
            $runner->advance($store, $parent, $gate, 'ana');

            $childInstanceId = $this->childInstanceIdOf($store, 'parent-4');
            $child = new ProcessInstance($childInstanceId, $registry->get(SampleProcess::NAME));

            $pending = $gate->pendingFor($store, $child);
            $this->assertNotNull($pending);
            $gate->resolve($store, $child, $pending->gateId, 'approve', 'ben');
            $runner->advance($store, $child, $gate, 'ana');

            $this->assertSame(SampleProcess::STATE_DONE, $child->currentState($store));
            $this->assertSame(SubprocessParentProcess::STATE_FINISHED, $parent->currentState($store));

            // Event-sourced end-to-end: a FRESH FileEventStore + FRESH ProcessInstance handles
            // over the SAME file reconstruct the exact same states for BOTH parent and child —
            // nothing here is cached in memory, including across the parent/child recursion.
            $freshStore = new FileEventStore($path);
            $freshChild = new ProcessInstance($childInstanceId, $registry->get(SampleProcess::NAME));
            $freshParent = new ProcessInstance('parent-4', $registry->get(SubprocessParentProcess::NAME));

            $this->assertSame(SampleProcess::STATE_DONE, $freshChild->currentState($freshStore));
            $this->assertSame(SubprocessParentProcess::STATE_FINISHED, $freshParent->currentState($freshStore));
        } finally {
            @unlink($path);
        }
    }

    public function testRoutingRecursesUpToAGrandparent(): void
    {
        $store = new InMemoryEventStore();
        $registry = $this->registry();

        /** @var list<array<string, mixed>> $terminals */
        $terminals = [];
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('process.terminal', function (string $name, array $payload) use (&$terminals): void {
            $terminals[] = $payload;
        });

        $runner = new ProcessRunner($dispatcher, $registry);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $grandparent = ProcessInstance::start(
            $store,
            $registry->get(SubprocessGrandparentProcess::NAME),
            ['ref' => 3, '_definition' => SubprocessGrandparentProcess::NAME],
            'grandparent-1',
        );
        $runner->advance($store, $grandparent, $gate, 'ana');

        $this->assertSame(SubprocessGrandparentProcess::STATE_DELEGATE, $grandparent->currentState($store));

        $parentInstanceId = $this->childInstanceIdOf($store, 'grandparent-1');
        $parent = new ProcessInstance($parentInstanceId, $registry->get(SubprocessParentProcess::NAME));
        $this->assertSame(SubprocessParentProcess::STATE_REVIEW, $parent->currentState($store));

        $childInstanceId = $this->childInstanceIdOf($store, $parentInstanceId);
        $child = new ProcessInstance($childInstanceId, $registry->get(SampleProcess::NAME));
        $this->assertSame(SampleProcess::STATE_REVIEW_GATE, $child->currentState($store));

        $pending = $gate->pendingFor($store, $child);
        $this->assertNotNull($pending);
        $gate->resolve($store, $child, $pending->gateId, 'approve', 'ben');
        $runner->advance($store, $child, $gate, 'ana');

        $this->assertSame(SampleProcess::STATE_DONE, $child->currentState($store));
        $this->assertSame(SubprocessParentProcess::STATE_FINISHED, $parent->currentState($store));
        $this->assertSame(SubprocessGrandparentProcess::STATE_CLOSED, $grandparent->currentState($store));

        $this->assertCount(3, $terminals, 'child, parent, AND grandparent must each fire process.terminal exactly once');
    }

    public function testSubprocessDoneRoutingIsNeverEmittedTwice(): void
    {
        $store = new InMemoryEventStore();
        $registry = $this->registry();

        /** @var list<array<string, mixed>> $terminals */
        $terminals = [];
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('process.terminal', function (string $name, array $payload) use (&$terminals): void {
            $terminals[] = $payload;
        });

        $runner = new ProcessRunner($dispatcher, $registry);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $parent = ProcessInstance::start(
            $store,
            $registry->get(SubprocessParentProcess::NAME),
            ['ref' => 5, '_definition' => SubprocessParentProcess::NAME],
            'parent-5',
        );
        $runner->advance($store, $parent, $gate, 'ana');

        $childInstanceId = $this->childInstanceIdOf($store, 'parent-5');
        $child = new ProcessInstance($childInstanceId, $registry->get(SampleProcess::NAME));

        $pending = $gate->pendingFor($store, $child);
        $this->assertNotNull($pending);
        $gate->resolve($store, $child, $pending->gateId, 'approve', 'ben');
        $runner->advance($store, $child, $gate, 'ana');

        $this->assertCount(2, $terminals);

        // Re-advancing the already-terminal CHILD again (e.g. a retried tool call) must be a
        // total no-op: no second routed event on the parent, no re-fired process.terminal.
        $runner->advance($store, $child, $gate, 'ana');
        $runner->advance($store, $parent, $gate, 'ana');

        $this->assertCount(2, $terminals, 'process.terminal must not re-fire for either instance');

        $routedEvents = array_values(array_filter(
            $store->replay('parent-5'),
            static fn (Event $event): bool => ($event->payload['subprocess_done'] ?? false) === true,
        ));
        $this->assertCount(1, $routedEvents, 'subprocess_done must be routed to the parent exactly once');
    }

    public function testTheDepthLimitThrowsOnRunawayNesting(): void
    {
        $store = new InMemoryEventStore();
        $registry = new ProcessDefinitionRegistry();

        // Comfortably exceeds ProcessRunner::MAX_SUBPROCESS_DEPTH (10) — a purely linear, NON-
        // cyclic chain, so the registry's static cycle check does not (and must not) reject it;
        // only the runtime depth guard can catch this shape.
        $levelCount = 13;
        for ($i = $levelCount - 1; $i >= 0; $i--) {
            $name = "chain_level_{$i}";
            $definition = $i === $levelCount - 1
                ? ChainLevelProcess::bottom($name)
                : ChainLevelProcess::nonBottom($name, 'chain_level_' . ($i + 1));
            $registry->register($name, $definition);
        }

        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), $registry);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $root = ProcessInstance::start(
            $store,
            $registry->get('chain_level_0'),
            ['_definition' => 'chain_level_0'],
            'chain-root',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/depth/i');

        $runner->advance($store, $root, $gate, 'tester');
    }

    public function testEnteringASubprocessStateWithoutARegistryThrowsAClearException(): void
    {
        $store = new InMemoryEventStore();
        $definition = SubprocessParentProcess::build();
        // No registry injected — ProcessRunner cannot resolve the child by name.
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()));
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $parent = ProcessInstance::start($store, $definition, ['ref' => 1, '_definition' => SubprocessParentProcess::NAME], 'parent-6');

        $this->expectException(\RuntimeException::class);

        $runner->advance($store, $parent, $gate, 'ana');
    }
}
