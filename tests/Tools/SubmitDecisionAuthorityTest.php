<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests\Tools;

use Milpa\EventStore\Event;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who answers a gate is WHO THE HOST AUTHENTICATED, never who the caller says it is.
 *
 * Drives the three tools through a real {@see ToolRegistry} — the same path an MCP host takes —
 * so the {@see ToolContext} each call carries is the only identity the engine sees. The attack
 * these tests pin down: an agent opens a gate with its own work, then submits the decision on it
 * while naming some other principal in the arguments. Author ≠ approver only holds if that name
 * is ignored and the authenticated principal is the one judged.
 */
final class SubmitDecisionAuthorityTest extends TestCase
{
    private InMemoryEventStore $store;

    private ToolRegistry $tools;

    private ProcessSubmitDecisionTool $submit;

    private ProcessInstantiateTool $instantiate;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();

        $definitions = new ProcessDefinitionRegistry();
        $definitions->register(SampleProcess::NAME, SampleProcess::build());

        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()));
        $this->submit = new ProcessSubmitDecisionTool($this->store, $gate, $runner, $definitions);
        $this->instantiate = new ProcessInstantiateTool($this->store, $gate, $runner, $definitions);

        $this->tools = new ToolRegistry(new NullLogger());
        $scanner = new ToolScanner($this->tools);
        $scanner->scan($this->instantiate);
        $scanner->scan(new ProcessListPendingApprovalsTool($this->store, $gate, $definitions));
        $scanner->scan($this->submit);
    }

    private static function caller(string $principal): ToolContext
    {
        return ToolContext::mcp('req-' . $principal, $principal, ['*']);
    }

    /**
     * @return array{0: string, 1: string} instance id and gate id of a run the agent opened
     */
    private function agentOpensAGate(): array
    {
        $started = $this->tools->call('process_instantiate', [
            'definition' => SampleProcess::NAME,
            'inputs' => ['ref' => 1],
        ], self::caller('agent:writer'));
        $this->assertTrue($started->success, (string) json_encode($started->data));

        $pending = $this->tools->call('process_list_pending_approvals', [], self::caller('agent:writer'));

        return [$started->data['instance_id'], $pending->data['pending'][0]['gate_id']];
    }

    /** @return list<Event> the decision events appended after the gate opened */
    private function decisions(string $instanceId): array
    {
        return array_values(array_filter(
            $this->store->replay($instanceId),
            static fn (Event $event): bool => in_array($event->type, ['approve', 'reject'], true),
        ));
    }

    public function testAnAgentCannotApproveItsOwnWorkByNamingAnotherPrincipal(): void
    {
        [$instanceId, $gateId] = $this->agentOpensAGate();

        $result = $this->tools->call('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'approve',
            'principal' => 'human:reviewer',
        ], self::caller('agent:writer'));

        $this->assertFalse($result->success, 'the agent that opened the gate approved it by naming someone else');
        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
        $this->assertSame([], $this->decisions($instanceId));
    }

    public function testTheRecordedApproverIsTheAuthenticatedCallerNotTheDeclaredOne(): void
    {
        [$instanceId, $gateId] = $this->agentOpensAGate();

        $result = $this->tools->call('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'approve',
            'principal' => 'human:somebody-else',
        ], self::caller('human:reviewer'));

        $this->assertTrue($result->success, (string) json_encode($result->data));
        $this->assertSame(SampleProcess::STATE_DONE, $result->data['current_state']);
        $decisions = $this->decisions($instanceId);
        $this->assertCount(1, $decisions);
        $this->assertSame('human:reviewer', $decisions[0]->payload['by']);
    }

    public function testADifferentAuthenticatedPrincipalMayApprove(): void
    {
        [$instanceId, $gateId] = $this->agentOpensAGate();

        $result = $this->tools->call('process_submit_decision', [
            'instance_id' => $instanceId,
            'gate_id' => $gateId,
            'decision' => 'approve',
        ], self::caller('human:reviewer'));

        $this->assertTrue($result->success, (string) json_encode($result->data));
        $this->assertSame(SampleProcess::STATE_DONE, $result->data['current_state']);
    }

    public function testADecisionWithNoAuthenticatedCallerIsRefused(): void
    {
        [$instanceId, $gateId] = $this->agentOpensAGate();

        $result = $this->submit->submit($instanceId, $gateId, 'approve');

        $this->assertFalse($result->success);
        $this->assertSame('UNAUTHENTICATED', $result->error);
        $this->assertSame([], $this->decisions($instanceId));
    }

    public function testAnEarlierCallersIdentityDoesNotCarryOverToTheNextCall(): void
    {
        [$instanceId, $gateId] = $this->agentOpensAGate();
        $this->submit->setCurrentContext(self::caller('human:reviewer'));
        $this->submit->submit('does-not-exist', $gateId, 'approve');

        $result = $this->submit->submit($instanceId, $gateId, 'approve');

        $this->assertFalse($result->success);
        $this->assertSame('UNAUTHENTICATED', $result->error);
        $this->assertSame([], $this->decisions($instanceId));
    }

    public function testARunWithNoAuthenticatedRequesterIsNotStarted(): void
    {
        $result = $this->instantiate->instantiate(SampleProcess::NAME, ['ref' => 1]);

        $this->assertFalse($result->success);
        $this->assertSame('UNAUTHENTICATED', $result->error);
        $this->assertSame([], $this->store->streams());
    }

    public function testAnEarlierRequesterDoesNotCarryOverToTheNextRun(): void
    {
        $this->instantiate->setCurrentContext(self::caller('human:reviewer'));
        $this->instantiate->instantiate('not_a_real_process', []);

        $result = $this->instantiate->instantiate(SampleProcess::NAME, ['ref' => 1]);

        $this->assertFalse($result->success);
        $this->assertSame('UNAUTHENTICATED', $result->error);
    }

    public function testADeclaredRequesterInTheInputsIsOverwrittenByTheAuthenticatedOne(): void
    {
        $started = $this->tools->call('process_instantiate', [
            'definition' => SampleProcess::NAME,
            'inputs' => ['ref' => 1, '_requester' => 'human:reviewer'],
        ], self::caller('agent:writer'));
        $gateId = $this->tools->call('process_list_pending_approvals', [], self::caller('agent:writer'))->data['pending'][0]['gate_id'];

        $result = $this->tools->call('process_submit_decision', [
            'instance_id' => $started->data['instance_id'],
            'gate_id' => $gateId,
            'decision' => 'approve',
        ], self::caller('agent:writer'));

        $this->assertSame('SELF_APPROVAL_FORBIDDEN', $result->error);
    }
}
