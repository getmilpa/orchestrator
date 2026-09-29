<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests\Tools;

use Milpa\EventStore\InMemoryEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A gate is answered by a VERIFIED actor — never by a transport's placeholder (greenhouse decisions/0528).
 *
 * #30 made the approver the authenticated caller. But on a process-trusted transport the "caller" is a
 * placeholder, not a person: MCP over stdio is `stdio` for whoever holds the pipe, and a terminal is
 * `local-shell` for whoever has a shell. Author ≠ approver still held — and so an agent over MCP could
 * approve a gate a HUMAN opened, because `stdio` is not that human. These tests drive the tools through a
 * real {@see ToolRegistry} with the contexts the hosts actually build.
 */
final class SubmitDecisionNeedsAVerifiedActorTest extends TestCase
{
    private InMemoryEventStore $store;

    private ToolRegistry $tools;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();

        $definitions = new ProcessDefinitionRegistry();
        $definitions->register(SampleProcess::NAME, SampleProcess::build());

        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()));

        $this->tools = new ToolRegistry(new NullLogger());
        $scanner = new ToolScanner($this->tools);
        $scanner->scan(new ProcessInstantiateTool($this->store, $gate, $runner, $definitions));
        $scanner->scan(new ProcessListPendingApprovalsTool($this->store, $gate, $definitions));
        $scanner->scan(new ProcessSubmitDecisionTool($this->store, $gate, $runner, $definitions));
    }

    /** @return array{0: string, 1: string} */
    private function opens(ToolContext $who): array
    {
        $started = $this->tools->call('process_instantiate', ['definition' => SampleProcess::NAME, 'inputs' => ['ref' => 1]], $who);
        $this->assertTrue($started->success, (string) json_encode($started->toArray()));
        $pending = $this->tools->call('process_list_pending_approvals', [], $who);

        return [$started->data['instance_id'], $pending->data['pending'][0]['gate_id']];
    }

    /** @return list<string> who resolved the gate, in order */
    private function approvers(string $instanceId): array
    {
        $by = [];
        foreach ($this->store->replay($instanceId) as $event) {
            if (\in_array($event->type, ['approve', 'reject'], true)) {
                $by[] = (string) $event->payload['by'];
            }
        }

        return $by;
    }

    /** @return iterable<string, array{ToolContext}> */
    public static function unverified(): iterable
    {
        yield 'an agent over MCP stdio' => [ToolContext::stdio('mcp-1')];
        yield 'an unsigned terminal' => [ToolContext::cli()];
        yield 'the TUI' => [ToolContext::tui()];
        yield 'an MCP caller the host named nobody' => [ToolContext::mcp('mcp-2', null, ['*'])];
    }

    #[DataProvider('unverified')]
    public function testAnUnverifiedCallerCannotApproveAGateAHumanOpened(ToolContext $agent): void
    {
        [$instanceId, $gateId] = $this->opens(ToolContext::web('actor:rod', ['*']));

        $result = $this->tools->call('process_submit_decision', ['instance_id' => $instanceId, 'gate_id' => $gateId, 'decision' => 'approve'], $agent);

        $this->assertFalse($result->success, (string) json_encode($result->toArray()));
        $this->assertSame('UNVERIFIED_APPROVER', $result->error);
        $this->assertStringContainsString('verified', (string) $result->data);
        $this->assertSame([], $this->approvers($instanceId));
    }

    public function testAVerifiedHumanApprovesAGateAnAgentOpenedOverMcp(): void
    {
        [$instanceId, $gateId] = $this->opens(ToolContext::stdio('mcp-1'));

        $result = $this->tools->call('process_submit_decision', ['instance_id' => $instanceId, 'gate_id' => $gateId, 'decision' => 'approve'], ToolContext::web('actor:rod', ['*']));

        $this->assertTrue($result->success, (string) json_encode($result->toArray()));
        $this->assertSame(SampleProcess::STATE_DONE, $result->data['current_state']);
        $this->assertSame(['actor:rod'], $this->approvers($instanceId));
    }

    public function testAVerifiedHumanStillCannotApproveTheGateTheyOpened(): void
    {
        [$instanceId, $gateId] = $this->opens(ToolContext::web('actor:rod', ['*']));

        $result = $this->tools->call('process_submit_decision', ['instance_id' => $instanceId, 'gate_id' => $gateId, 'decision' => 'approve'], ToolContext::web('actor:rod', ['*']));

        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
        $this->assertSame([], $this->approvers($instanceId));
    }
}
