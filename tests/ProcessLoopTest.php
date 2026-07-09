<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Drives the whole `sample_process` process loop through the 3 tools end to end: instantiate
 * (auto-advances to the gate), list the pending decision, submit a decision (auto-advances
 * again), and proves it is genuinely event-sourced (a FRESH {@see FileEventStore} over the same
 * file reconstructs the same state, with no in-memory shortcut) and that reaching a terminal
 * state fires `process.terminal` exactly once via `milpa/events`.
 */
final class ProcessLoopTest extends TestCase
{
    private string $path;

    /** @var list<array<string, mixed>> */
    private array $firedTerminalEvents = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/orchestrator-loop-' . uniqid('', true) . '.jsonl';
        $this->firedTerminalEvents = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * @return array{0: ProcessInstantiateTool, 1: ProcessListPendingApprovalsTool, 2: ProcessSubmitDecisionTool, 3: FileEventStore}
     */
    private function tools(): array
    {
        $store = new FileEventStore($this->path);

        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('process.terminal', function (string $name, array $payload): void {
            $this->firedTerminalEvents[] = $payload;
        });

        $registry = new ProcessDefinitionRegistry();
        $registry->register(SampleProcess::NAME, SampleProcess::build());

        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner($dispatcher);

        $instantiate = new ProcessInstantiateTool($store, $gate, $runner, $registry);
        $instantiate->setCurrentContext(ToolContext::cli());

        $list = new ProcessListPendingApprovalsTool($store, $gate, $registry);
        $submit = new ProcessSubmitDecisionTool($store, $gate, $runner, $registry);

        return [$instantiate, $list, $submit, $store];
    }

    public function testInstantiateAutoAdvancesAllTheWayToTheReviewGate(): void
    {
        [$instantiate] = $this->tools();

        $result = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1]);

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->data['instance_id']);
        $this->assertSame('review_gate', $result->data['current_state']);
    }

    public function testInstantiateWithAnUnknownDefinitionIsAClearError(): void
    {
        [$instantiate] = $this->tools();

        $result = $instantiate->instantiate('not_a_real_process', []);

        $this->assertFalse($result->success);
        $this->assertSame('UNKNOWN_DEFINITION', $result->error);
    }

    public function testListPendingApprovalsShowsTheOpenGateWithItsOptions(): void
    {
        [$instantiate, $list] = $this->tools();
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];

        $result = $list->list();

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['pending']);
        $row = $result->data['pending'][0];
        $this->assertSame($instanceId, $row['instance_id']);
        $this->assertSame('reviewer', $row['assignee']);
        $options = $row['options'];
        sort($options);
        $this->assertSame(['approve', 'reject'], $options);
        $this->assertSame('stub-decision-surface', $row['artifact']['component']);
    }

    public function testListingPendingApprovalsTwiceDoesNotReopenTheGate(): void
    {
        [$instantiate, $list, , $store] = $this->tools();
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];

        $list->list();
        $list->list();

        $opened = array_values(array_filter(
            $store->replay($instanceId),
            static fn (Event $event): bool => $event->type === 'GateOpened',
        ));
        $this->assertCount(1, $opened, 'listing pending approvals must not append a redundant GateOpened event');
    }

    public function testSubmitApproveReachesTerminalFiresProcessTerminalAndReplaysCleanFromAFreshStore(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'approve', 'human:reviewer');

        $this->assertTrue($result->success);
        $this->assertSame('done', $result->data['current_state']);

        $this->assertCount(1, $this->firedTerminalEvents);
        $this->assertSame($instanceId, $this->firedTerminalEvents[0]['instance_id']);
        $this->assertSame('done', $this->firedTerminalEvents[0]['final_state']);
        $this->assertSame(1, $this->firedTerminalEvents[0]['context']['ref']);

        // Event-sourced end-to-end: a FRESH FileEventStore + a FRESH ProcessInstance handle over
        // the SAME file reconstructs the exact same state — nothing here is cached in memory.
        $freshStore = new FileEventStore($this->path);
        $attached = new ProcessInstance($instanceId, SampleProcess::build());
        $this->assertSame('done', $attached->currentState($freshStore));
    }

    public function testAdvancingAnAlreadyTerminalInstanceAgainDoesNotRefireProcessTerminal(): void
    {
        [$instantiate, $list, $submit, $store] = $this->tools();
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];
        $submit->submit($instanceId, $gateId, 'approve', 'human:reviewer');

        $this->assertCount(1, $this->firedTerminalEvents);

        // Re-advancing an already-terminal instance directly (bypassing the tools, and through a
        // brand-new ProcessRunner/dispatcher pair subscribed the same way) must be a total no-op.
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe('process.terminal', function (string $name, array $payload): void {
            $this->firedTerminalEvents[] = $payload;
        });
        $runner = new ProcessRunner($dispatcher);
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $instance = new ProcessInstance($instanceId, SampleProcess::build());

        $runner->advance($store, $instance, $gate, 'human:reviewer');

        $this->assertCount(1, $this->firedTerminalEvents, 'process.terminal must fire exactly once per instance, ever');
    }

    public function testSubmitRejectReturnsToAFreshReviewGate(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'reject', 'human:reviewer');

        $this->assertTrue($result->success);
        // ProcessRunner drives draft --submit--> review_gate again and opens a fresh gate — the
        // revise-and-resubmit loop, all within this one process_submit_decision call.
        $this->assertSame('review_gate', $result->data['current_state']);
        $this->assertCount(0, $this->firedTerminalEvents);

        $pendingAgain = $list->list()->data['pending'];
        $this->assertCount(1, $pendingAgain);
        $this->assertSame($instanceId, $pendingAgain[0]['instance_id']);
    }

    public function testSubmitDecisionSelfApprovalIsRejectedCleanly(): void
    {
        [$instantiate, $list, $submit] = $this->tools();
        // ProcessInstantiateTool records ToolContext::cli()'s principal ('cli') as the requester.
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];
        $gateId = $list->list()->data['pending'][0]['gate_id'];

        $result = $submit->submit($instanceId, $gateId, 'approve', 'cli');

        $this->assertFalse($result->success);
        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
    }

    public function testSubmitDecisionWithAnUnknownGateIsAClearError(): void
    {
        [$instantiate, , $submit] = $this->tools();
        $instanceId = $instantiate->instantiate(SampleProcess::NAME, ['ref' => 1])->data['instance_id'];

        $result = $submit->submit($instanceId, 'never_opened_gate', 'approve', 'human:reviewer');

        $this->assertFalse($result->success);
        $this->assertSame('GATE_NOT_PENDING', $result->error);
    }

    public function testSubmitDecisionForAnUnknownInstanceIsAClearError(): void
    {
        [, , $submit] = $this->tools();

        $result = $submit->submit('does-not-exist', 'review_gate_gate', 'approve', 'human:reviewer');

        $this->assertFalse($result->success);
        $this->assertSame('UNKNOWN_INSTANCE', $result->error);
    }
}
