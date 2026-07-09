<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use PHPUnit\Framework\TestCase;

final class ProcessInstanceTest extends TestCase
{
    public function testStartYieldsTheInitialStateAndAccumulatedInputsInContext(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();

        $instance = ProcessInstance::start($store, $definition, ['ref' => 1], 'proc-1');

        $this->assertSame('proc-1', $instance->instanceId);
        $this->assertSame('draft', $instance->currentState($store));
        $this->assertSame(1, $instance->context($store)['ref']);
    }

    public function testStartAppendsProcessStartedThenStateEnteredToTheStore(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();

        ProcessInstance::start($store, $definition, ['ref' => 1], 'proc-2');

        $events = $store->replay('proc-2');

        $this->assertCount(2, $events);
        $this->assertSame('ProcessStarted', $events[0]->type);
        $this->assertSame(['ref' => 1], $events[0]->payload);
        $this->assertSame('StateEntered', $events[1]->type);
        $this->assertSame(['state' => 'draft'], $events[1]->payload);
    }

    public function testAFreshReplayThroughAnIndependentInstanceHandleReconstructsTheSameState(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();

        $original = ProcessInstance::start($store, $definition, ['ref' => 7], 'proc-3');
        $store->append(new Event('proc-3', 'submit', [], $store->nextSeq()));

        // A brand-new EventStore instance would not share state here since InMemoryEventStore is
        // process-local — the durability proof across a FRESH store lives in ProcessLoopTest
        // (FileEventStore-backed). This proves the narrower invariant: a brand-new
        // ProcessInstance handle built WITHOUT calling start() again reconstructs the same state
        // as the original handle, over the SAME store.
        $attached = new ProcessInstance('proc-3', $definition);

        $this->assertSame('review_gate', $attached->currentState($store));
        $this->assertSame($original->currentState($store), $attached->currentState($store));
    }

    public function testStartGeneratesAnInstanceIdWhenNoneIsGiven(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();

        $instance = ProcessInstance::start($store, $definition, ['ref' => 1]);

        $this->assertNotSame('', $instance->instanceId);
        $this->assertSame('draft', $instance->currentState($store));
    }

    public function testTwoStartedInstancesDoNotShareState(): void
    {
        $store = new InMemoryEventStore();
        $definition = SampleProcess::build();

        $one = ProcessInstance::start($store, $definition, ['ref' => 1], 'proc-a');
        $two = ProcessInstance::start($store, $definition, ['ref' => 2], 'proc-b');
        $store->append(new Event('proc-a', 'submit', [], $store->nextSeq()));

        $this->assertSame('review_gate', $one->currentState($store));
        $this->assertSame('draft', $two->currentState($store));
        $this->assertSame(2, $two->context($store)['ref']);
    }
}
