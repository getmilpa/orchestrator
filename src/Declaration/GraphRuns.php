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

namespace Milpa\Orchestrator\Declaration;

use Milpa\EventStore\EventStoreInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Workflow\Exceptions\SelfApprovalException;
use Milpa\Workflow\Exceptions\TransitionNotAllowedException;

/**
 * Starting, resuming and inspecting declared graphs — the one collaborator the `graph:*` operations work through.
 *
 * It exists so that every surface reaches a graph the same way. A run started from the terminal parks in the
 * log; the decision it is waiting for shows up in the same list a Desktop or an MCP client reads; and whoever
 * answers it does so through the same door, with the same refusals. The engine already guaranteed that — this
 * just gives it one entrance instead of four callers assembling the same four collaborators by hand.
 */
final class GraphRuns
{
    private readonly ProcessDefinitionRegistry $definitions;

    public function __construct(
        private readonly GraphRegistry $graphs,
        private readonly EventStoreInterface $store,
        private readonly HumanGate $gate,
        private readonly MilpaEventDispatcherInterface $dispatcher,
    ) {
        $this->definitions = new ProcessDefinitionRegistry();
    }

    /**
     * What this app can run, and what each one needs to start.
     *
     * @return list<array{name: string, description: string, channels: list<string>, required: list<string>, nodes: list<string>}>
     */
    public function catalogue(): array
    {
        $rows = [];

        foreach ($this->graphs->names() as $name) {
            $graph = $this->graphs->get($name);
            $required = [];
            foreach ($graph->channels as $channel => $default) {
                if ($default === null && !\in_array($channel, ['verdict', 'url'], true)) {
                    $required[] = $channel;
                }
            }

            $rows[] = [
                'name' => $graph->name,
                'description' => $graph->description,
                'channels' => array_keys($graph->channels),
                'required' => $required,
                'nodes' => array_keys($graph->operations),
            ];
        }

        return $rows;
    }

    /**
     * Starts a run and advances it until it finishes or needs somebody.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{instance_id: string, state: string, awaiting: ?string}
     */
    public function start(string $name, array $inputs, string $requester): array
    {
        $graph = $this->register($name);

        $inputs['_requester'] = $requester;
        $inputs['_definition'] = $name;

        $instance = ProcessInstance::start($this->store, $graph->definition, $inputs);
        $this->runnerFor($graph)->advance($this->store, $instance, $this->gate, $requester);

        return $this->positionOf($instance);
    }

    /**
     * Every decision waiting for a human, across every run of every declared graph.
     *
     * Each row also says WHICH graph it belongs to and WHO started the run, which the engine's own row
     * does not carry — and without them a surface can list a decision it cannot answer.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        foreach ($this->graphs->names() as $name) {
            $this->register($name);
        }

        $result = (new ProcessListPendingApprovalsTool($this->store, $this->gate, $this->definitions))->list();

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->success ? ($result->data['pending'] ?? []) : [];

        // WHICH GRAPH, and WHO ASKED — the engine's own row does not carry either, and without them a
        // surface can list a waiting decision and not answer it: `graph:decide` needs the graph's name,
        // and a human deciding deserves to know on whose behalf the run was started. Both are already in
        // the instance's context, put there when it started.
        foreach ($rows as $index => $row) {
            $instanceId = (string) ($row['instance_id'] ?? '');

            if ($instanceId === '') {
                continue;
            }

            $context = [];
            foreach ($this->store->replay($instanceId) as $event) {
                $context = array_merge($context, $event->payload);
            }

            $rows[$index]['graph'] = (string) ($context['_definition'] ?? '');
            $rows[$index]['requester'] = (string) ($context['_requester'] ?? '');
        }

        return $rows;
    }

    /**
     * Answers one waiting decision and lets the run continue.
     *
     * @return array{instance_id: string, state: string, awaiting: ?string}
     *
     * @throws SelfApprovalException         when the principal answering is the one that asked
     * @throws TransitionNotAllowedException when the answer is not one of the offered options
     */
    public function decide(string $name, string $instanceId, string $decision, string $principal): array
    {
        $graph = $this->register($name);
        $instance = new ProcessInstance($instanceId, $graph->definition);

        $waiting = $this->gate->pendingFor($this->store, $instance);
        if ($waiting === null) {
            throw new GraphDeclarationException("Run '{$instanceId}' is not waiting for a decision.");
        }

        $this->gate->resolve($this->store, $instance, $waiting->gateId, $decision, $principal);

        $requester = (string) ($instance->context($this->store)['_requester'] ?? $principal);
        $this->runnerFor($graph)->advance($this->store, $instance, $this->gate, $requester);

        return $this->positionOf($instance);
    }

    /**
     * Where a run stands, and everything it has accumulated.
     *
     * @return array{instance_id: string, state: string, awaiting: ?string, context: array<string, mixed>}
     */
    public function show(string $name, string $instanceId): array
    {
        $graph = $this->register($name);
        $instance = new ProcessInstance($instanceId, $graph->definition);

        return $this->positionOf($instance) + ['context' => $instance->context($this->store)];
    }

    private function register(string $name): CompiledGraph
    {
        $graph = $this->graphs->get($name);

        if (!$this->definitions->has($name)) {
            $this->definitions->register($name, $graph->definition);
        }

        return $graph;
    }

    private function runnerFor(CompiledGraph $graph): ProcessRunner
    {
        return new ProcessRunner($this->dispatcher, $this->definitions, new NodeInvoker($graph));
    }

    /** @return array{instance_id: string, state: string, awaiting: ?string} */
    private function positionOf(ProcessInstance $instance): array
    {
        $waiting = $this->gate->pendingFor($this->store, $instance);

        return [
            'instance_id' => $instance->instanceId,
            'state' => $instance->currentState($this->store),
            'awaiting' => $waiting?->gateId,
        ];
    }
}
