<?php

/**
 * This file is part of milpa/orchestrator — the generic event-sourced process engine of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/orchestrator
 */

declare(strict_types=1);

namespace Milpa\Orchestrator\Tools;

use Milpa\EventStore\EventStoreInterface;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\ToolResult;
use Milpa\Workflow\Exceptions\SelfApprovalException;

/**
 * `process_submit_decision` — resolves an open gate via {@see HumanGate::resolve()} then drives
 * the process forward again via {@see ProcessRunner} (so a decision that reaches a terminal state
 * fires `process.terminal` — see {@see ProcessRunner}'s own docblock — and a decision that loops
 * back to a gated state re-opens a fresh gate, both in one call). {@see HumanGate::resolve()}'s
 * failure modes are each surfaced as a distinct, clean {@see ToolResult::error()} instead of a
 * generic catch-all — `SelfApprovalException` MUST be caught before the generic
 * `\RuntimeException` arm since it extends it.
 *
 * Parameters are named `instance_id`/`gate_id` (snake_case), not `instanceId`/`gateId`, on
 * purpose: {@see \Milpa\ToolRuntime\ToolScanner::buildSchema()} takes a tool's wire argument
 * names directly from `ReflectionParameter::getName()` with no case conversion, so the PHP
 * parameter name IS the JSON-RPC argument key a caller must send — this family's wire
 * convention is snake_case throughout.
 *
 * This tool touches NO domain entity — reaching a terminal state is surfaced purely via the
 * `process.terminal` event {@see ProcessRunner} dispatches; a consumer subscribes to that event
 * to run whatever domain effect its own process definition's terminal state should trigger.
 */
final class ProcessSubmitDecisionTool
{
    use ResolvesDefinitionNameTrait;

    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly HumanGate $gate,
        private readonly ProcessRunner $runner,
        private readonly ProcessDefinitionRegistry $registry,
    ) {
    }

    /**
     * Resolves `$gate_id` for `$instance_id` with `$decision` and auto-advances the process again.
     *
     * @return ToolResult with data `{instance_id: string, current_state: string}` on success
     */
    #[Tool('process_submit_decision', 'Resolve an open gate (e.g. grant or reject) for a process instance')]
    public function submit(
        #[Param('The process instance id', required: true)]
        string $instance_id,
        #[Param('The gate id being resolved (from process_list_pending_approvals)', required: true)]
        string $gate_id,
        #[Param('The decision — must be one of the gate\'s offered options', required: true)]
        string $decision,
        #[Param('The resolving principal; must differ from whoever opened the gate', required: true)]
        string $principal,
    ): ToolResult {
        $definitionName = $this->definitionNameFor($this->store, $instance_id);
        if ($definitionName === null || !$this->registry->has($definitionName)) {
            return ToolResult::error('UNKNOWN_INSTANCE', "No process instance found for '{$instance_id}'.");
        }

        $instance = new ProcessInstance($instance_id, $this->registry->get($definitionName));

        try {
            $this->gate->resolve($this->store, $instance, $gate_id, $decision, $principal);
        } catch (SelfApprovalException $e) {
            return ToolResult::error('SELF_APPROVAL_FORBIDDEN', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error('INVALID_DECISION', $e->getMessage());
        } catch (\RuntimeException $e) {
            return ToolResult::error('GATE_NOT_PENDING', $e->getMessage());
        }

        // Keep recording the ORIGINAL requester of any gate this decision reopens (a
        // revise-and-resubmit loop) — see ProcessInstantiateTool's `_requester` context key.
        $requester = (string) ($instance->context($this->store)['_requester'] ?? $principal);
        $this->runner->advance($this->store, $instance, $this->gate, $requester);

        return ToolResult::success([
            'instance_id' => $instance->instanceId,
            'current_state' => $instance->currentState($this->store),
        ]);
    }
}
