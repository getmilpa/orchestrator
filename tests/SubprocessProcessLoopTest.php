<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Orchestrator\Tests\Fixtures\SubprocessParentProcess;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Drives a subprocess-composed process through the SAME 3 tools {@see ProcessLoopTest} drives a
 * plain process through: `process_instantiate` on the PARENT auto-advances into a subprocess
 * state, which (via {@see ProcessRunner}, wired with a {@see ProcessDefinitionRegistry} this time)
 * starts the CHILD and auto-advances IT to its own gate. Proves the family's "unified inbox at any
 * nesting depth" claim: `process_list_pending_approvals` — which scans EVERY stream the store has
 * ever seen — surfaces the CHILD's gate without either tool needing any subprocess-specific code.
 * `process_submit_decision` resolves the child's gate and, in the SAME call, drives the routing
 * all the way to the parent's own terminal state.
 */
final class SubprocessProcessLoopTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/orchestrator-subprocess-loop-' . uniqid('', true) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * @return array{0: ProcessInstantiateTool, 1: ProcessListPendingApprovalsTool, 2: ProcessSubmitDecisionTool}
     */
    private function tools(): array
    {
        $store = new FileEventStore($this->path);
        $dispatcher = new EventDispatcher(new NullLogger());

        $registry = new ProcessDefinitionRegistry();
        $registry->register(SampleProcess::NAME, SampleProcess::build());
        $registry->register(SubprocessParentProcess::NAME, SubprocessParentProcess::build());

        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner($dispatcher, $registry);

        $instantiate = new ProcessInstantiateTool($store, $gate, $runner, $registry);
        $instantiate->setCurrentContext(ToolContext::cli());

        $list = new ProcessListPendingApprovalsTool($store, $gate, $registry);
        $submit = new ProcessSubmitDecisionTool($store, $gate, $runner, $registry);

        return [$instantiate, $list, $submit];
    }

    public function testInstantiatingTheParentAutoAdvancesIntoTheChildsGate(): void
    {
        [$instantiate, $list] = $this->tools();

        $result = $instantiate->instantiate(SubprocessParentProcess::NAME, ['ref' => 1]);

        $this->assertTrue($result->success);
        $this->assertSame(SubprocessParentProcess::STATE_REVIEW, $result->data['current_state']);

        // process_list_pending_approvals scans every stream — it must surface the CHILD's gate
        // even though only the PARENT was ever named through process_instantiate.
        $pending = $list->list()->data['pending'];
        $this->assertCount(1, $pending);
        $this->assertNotSame($result->data['instance_id'], $pending[0]['instance_id'], 'the pending gate belongs to the CHILD instance, not the parent');
        $this->assertSame('reviewer', $pending[0]['assignee']);
    }

    public function testResolvingTheChildsGateAdvancesTheParentToItsTerminal(): void
    {
        [$instantiate, $list, $submit] = $this->tools();

        $parentInstanceId = $instantiate->instantiate(SubprocessParentProcess::NAME, ['ref' => 1])->data['instance_id'];
        $childPending = $list->list()->data['pending'][0];

        // ProcessInstantiateTool records ToolContext::cli()'s principal ('cli') as the requester
        // for the whole chain (parent AND child) — 'human:reviewer' resolves it without
        // self-approval.
        $result = $submit->submit($childPending['instance_id'], $childPending['gate_id'], 'approve', 'human:reviewer');

        $this->assertTrue($result->success);
        $this->assertSame(SampleProcess::STATE_DONE, $result->data['current_state']);

        // The routing back to the parent happened as part of THIS SAME submit() call — no gate
        // is pending anymore anywhere, since the parent reached its OWN terminal state too.
        $this->assertCount(0, $list->list()->data['pending']);

        // Prove the parent itself actually reached its terminal state, via a fresh replay.
        $freshStore = new FileEventStore($this->path);
        $parentInstance = new ProcessInstance($parentInstanceId, SubprocessParentProcess::build());
        $this->assertSame(SubprocessParentProcess::STATE_FINISHED, $parentInstance->currentState($freshStore));
    }
}
