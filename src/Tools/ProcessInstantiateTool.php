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
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * `process_instantiate` — starts a new process instance from a named process definition
 * (resolved through the injected {@see ProcessDefinitionRegistry}) and auto-advances it (via
 * {@see ProcessRunner}) up to its first human decision point or terminal state.
 *
 * `inputs` is declared `array` with `#[Param(type: 'object')]` (tool-runtime 0.6): the wire value
 * arrives as a plain associative PHP array (the host's JSON decode already produced it), so no
 * manual `json_decode()` is needed in this method body — see {@see Param}'s own docblock for why
 * a bare `array $inputs` (without the `type: 'object'` override) would infer JSON-Schema
 * `type: array` instead, rejecting an associative payload like `{"post_id": 1}`.
 */
final class ProcessInstantiateTool
{
    private ?ToolContext $context = null;

    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly HumanGate $gate,
        private readonly ProcessRunner $runner,
        private readonly ProcessDefinitionRegistry $registry,
    ) {
    }

    /** Captures the calling {@see ToolContext} — the tool scanner injects it when this method exists. */
    public function setCurrentContext(ToolContext $ctx): void
    {
        $this->context = $ctx;
    }

    /**
     * Starts `$definition` with `$inputs` and auto-advances it to its first gate or terminal state.
     *
     * @param array<string, mixed> $inputs
     *
     * @return ToolResult with data `{instance_id: string, current_state: string}` on success
     */
    #[Tool('process_instantiate', 'Start a process instance from a named process definition and run it to its first gate or terminal state')]
    public function instantiate(
        #[Param('Process definition name, as registered in the ProcessDefinitionRegistry', required: true)]
        string $definition,
        #[Param(
            'Definition-specific starting inputs (e.g. {"post_id": 1})',
            required: true,
            type: 'object',
        )]
        array $inputs,
    ): ToolResult {
        if (!$this->registry->has($definition)) {
            return ToolResult::error(
                'UNKNOWN_DEFINITION',
                "No process definition named '{$definition}'. Registered: " . implode(', ', $this->registry->names()) . '.',
            );
        }

        // Not `$this->context?->principal ?? 'unknown'`: PHPStan flags that nullsafe access as
        // `nullsafe.neverNull` at this family's phpstan level even though `$this->context`
        // genuinely defaults to `null` until `setCurrentContext()` runs.
        $requester = $this->context !== null ? ($this->context->principal ?? 'unknown') : 'unknown';

        // Carried in the process's own context so a LATER re-open of the same gate (a
        // revise-and-resubmit loop) keeps recording the ORIGINAL requester — see
        // ProcessSubmitDecisionTool — and so a cross-instance scan (process_list_pending_approvals)
        // can resolve which ProcessDefinition governs this instance — see ResolvesDefinitionNameTrait.
        $inputs['_requester'] = $requester;
        $inputs['_definition'] = $definition;

        $instance = ProcessInstance::start($this->store, $this->registry->get($definition), $inputs);
        $this->runner->advance($this->store, $instance, $this->gate, $requester);

        return ToolResult::success([
            'instance_id' => $instance->instanceId,
            'current_state' => $instance->currentState($this->store),
        ]);
    }
}
