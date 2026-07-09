<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Workflow\Exceptions\SelfApprovalException;
use PHPUnit\Framework\TestCase;

final class HumanGateTest extends TestCase
{
    private function instanceAtReviewGate(InMemoryEventStore $store, string $instanceId = 'proc-1'): ProcessInstance
    {
        $definition = SampleProcess::build();
        $instance = ProcessInstance::start($store, $definition, ['ref' => 1], $instanceId);
        $store->append(new Event($instanceId, 'submit', [], $store->nextSeq()));

        return $instance;
    }

    public function testOpenForAnInstanceAtReviewGateReturnsAPendingDecisionWithTheArtifact(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);

        $pending = (new HumanGate(new StubDecisionSurfaceFactory()))->openFor($store, $instance, 'ana');

        $this->assertSame('proc-1', $pending->instanceId);
        $this->assertSame('review_gate_gate', $pending->gateId);
        $this->assertSame('reviewer', $pending->assignee);

        $options = $pending->options;
        sort($options);
        $this->assertSame(['approve', 'reject'], $options);
    }

    public function testOpenForAppendsAGateOpenedEventCarryingTheRequesterAndOptions(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);

        (new HumanGate(new StubDecisionSurfaceFactory()))->openFor($store, $instance, 'ana');

        $events = $store->replay('proc-1');
        $opened = end($events);

        $this->assertNotFalse($opened);
        $this->assertSame('GateOpened', $opened->type);
        $this->assertSame('ana', $opened->payload['requester']);

        $options = $opened->payload['options'];
        sort($options);
        $this->assertSame(['approve', 'reject'], $options);
    }

    public function testOpenForThrowsWhenTheCurrentStateHasNoGate(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();
        $instance = ProcessInstance::start($store, $definition, ['ref' => 1], 'proc-draft');

        $this->expectException(\RuntimeException::class);

        (new HumanGate(new StubDecisionSurfaceFactory()))->openFor($store, $instance, 'ana');
    }

    public function testResolveWithApproveAdvancesTheInstanceToDone(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $pending = $gate->openFor($store, $instance, 'ana');

        $event = $gate->resolve($store, $instance, $pending->gateId, 'approve', 'ben');

        $this->assertSame('approve', $event->type);
        $this->assertSame(['by' => 'ben'], $event->payload);
        $this->assertSame('done', $instance->currentState($store));
    }

    public function testResolveWithRejectReturnsTheInstanceToDraft(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $pending = $gate->openFor($store, $instance, 'ana');

        $event = $gate->resolve($store, $instance, $pending->gateId, 'reject', 'ben');

        $this->assertSame('reject', $event->type);
        $this->assertSame('draft', $instance->currentState($store));
    }

    public function testResolvingWithTheSamePrincipalThatRequestedThrowsSelfApprovalException(): void
    {
        // Proves HumanGate delegates the D9 self-approval check to workflow's GateServiceInterface
        // (InMemoryGateService by default) rather than reimplementing it inline.
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $pending = $gate->openFor($store, $instance, 'ana');

        $this->expectException(SelfApprovalException::class);

        $gate->resolve($store, $instance, $pending->gateId, 'approve', 'ana');
    }

    public function testResolvingWithAnUnknownDecisionThrows(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $pending = $gate->openFor($store, $instance, 'ana');

        $this->expectException(\InvalidArgumentException::class);

        $gate->resolve($store, $instance, $pending->gateId, 'delete', 'ben');
    }

    public function testResolvingAGateThatWasNeverOpenedThrows(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $this->expectException(\RuntimeException::class);

        $gate->resolve($store, $instance, 'review_gate_gate', 'approve', 'ben');
    }

    public function testResolvingAnAlreadyResolvedGateAgainThrows(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $pending = $gate->openFor($store, $instance, 'ana');
        $gate->resolve($store, $instance, $pending->gateId, 'approve', 'ben');

        $this->expectException(\RuntimeException::class);

        $gate->resolve($store, $instance, $pending->gateId, 'approve', 'ben');
    }

    public function testPendingForReturnsNullWhenNoGateWasEverOpened(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();
        $instance = ProcessInstance::start($store, $definition, ['ref' => 1], 'proc-draft');
        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $this->assertNull($gate->pendingFor($store, $instance));
    }

    public function testPendingForFindsAnOpenGateWithoutReopeningIt(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $gate->openFor($store, $instance, 'ana');

        $pending = $gate->pendingFor($store, $instance);

        $this->assertNotNull($pending);
        $options = $pending->options;
        sort($options);
        $this->assertSame(['approve', 'reject'], $options);

        $opened = array_values(array_filter(
            $store->replay('proc-1'),
            static fn (Event $e): bool => $e->type === 'GateOpened',
        ));
        $this->assertCount(1, $opened, 'pendingFor() must not append a redundant GateOpened event');
    }

    public function testPendingForReturnsNullAfterTheGateHasBeenResolved(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $pending = $gate->openFor($store, $instance, 'ana');
        $gate->resolve($store, $instance, $pending->gateId, 'approve', 'ben');

        $this->assertNull($gate->pendingFor($store, $instance));
    }

    public function testPendingForReturnsNullWhenTheArtifactFactoryThrows(): void
    {
        $store = new InMemoryEventStore();
        $instance = $this->instanceAtReviewGate($store);

        // Open the gate with a WORKING factory (openFor() is a write path that must succeed
        // here), then read it back through a SEPARATE HumanGate bound to a THROWING factory —
        // proving pendingFor() (a read path) swallows the factory's failure into "nothing
        // pending" rather than propagating it, per its own docblock.
        (new HumanGate(new StubDecisionSurfaceFactory()))->openFor($store, $instance, 'ana');

        $reader = new HumanGate(new class () implements \Milpa\Orchestrator\DecisionSurfaceFactoryInterface {
            public function build(ProcessInstance $instance, array $transitions, array $context): \Milpa\Orchestrator\DecisionSurfaceInterface
            {
                throw new \RuntimeException('domain lookup failed');
            }
        });

        $this->assertNull($reader->pendingFor($store, $instance));
    }
}
