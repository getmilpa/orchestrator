<?php

/**
 * This file is part of milpa/orchestrator — the generic event-sourced process engine of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/orchestrator
 */

declare(strict_types=1);

namespace Milpa\Orchestrator\Tools;

use Milpa\EventStore\EventStoreInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\ToolResult;

/**
 * `process_list_pending_approvals` — a pure READ across every stream {@see EventStoreInterface}
 * has ever seen (via {@see EventStoreInterface::streams()}): which ones are currently awaiting a
 * human decision. Uses {@see HumanGate::pendingFor()} (never {@see HumanGate::openFor()}), so
 * listing never appends a redundant `GateOpened` event — see that method's docblock.
 *
 * Returns each pending decision's mounted {@see \Milpa\Live\ValueObjects\StateSnapshot} data
 * (`{component, data}`) rather than pre-rendered markup — turning a mounted `milpa/live`
 * component into markup/TUI text is a separate {@see
 * \Milpa\Live\Contracts\Rendering\ComponentRendererInterface}'s job this engine does not own (a
 * consumer pairs its {@see \Milpa\Orchestrator\DecisionSurfaceInterface} implementation with a
 * real renderer for that).
 */
final class ProcessListPendingApprovalsTool
{
    use ResolvesDefinitionNameTrait;

    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly HumanGate $gate,
        private readonly ProcessDefinitionRegistry $registry,
    ) {
    }

    /**
     * Lists every process instance currently awaiting a human decision, optionally filtered by
     * the gate's approver role.
     *
     * @return ToolResult with data `{pending: list<array{instance_id: string, gate_id: string, assignee: string, options: list<string>, artifact: array{component: string, data: array<string,mixed>}}>}`
     */
    #[Tool('process_list_pending_approvals', 'List process instances currently awaiting a human decision')]
    public function list(
        #[Param('Filter by the gate\'s approver role (e.g. "editor"); omit to list every pending decision', required: false)]
        ?string $assignee = null,
    ): ToolResult {
        $pending = [];

        foreach ($this->store->streams() as $streamId) {
            $definitionName = $this->definitionNameFor($this->store, $streamId);
            if ($definitionName === null || !$this->registry->has($definitionName)) {
                continue;
            }

            $instance = new ProcessInstance($streamId, $this->registry->get($definitionName));
            $decision = $this->gate->pendingFor($this->store, $instance);
            if ($decision === null) {
                continue;
            }
            if ($assignee !== null && $decision->assignee !== $assignee) {
                continue;
            }

            $snapshot = $decision->artifact->mount([], new ComponentContext(componentId: $decision->gateId));

            $pending[] = [
                'instance_id' => $decision->instanceId,
                'gate_id' => $decision->gateId,
                'assignee' => $decision->assignee,
                'options' => $decision->options,
                'artifact' => [
                    'component' => $snapshot->componentName,
                    'data' => $snapshot->data,
                ],
            ];
        }

        return ToolResult::success(['pending' => $pending]);
    }
}
