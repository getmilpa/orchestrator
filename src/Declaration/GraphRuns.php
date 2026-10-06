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

use Milpa\EventStore\Event;
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
 *
 * ── WHO RUNS THE NODES ──────────────────────────────────────────────────────────────────────────────────────────
 *
 * A run is advanced twice or more, by different people: once by whoever starts it, and again by whoever answers each
 * gate. Every node runs AS THE CALLER OF THE INVOCATION THAT REACHED IT — {@see Caller} — and is judged against what
 * that caller holds at that moment. The starter's authority is not kept at the gate for the approver to spend, and
 * the approver's is not lent back to the starter: nothing about authority is ever written to the log or read from
 * it. What the log keeps is who ran each node and who was refused one ({@see self::show()}'s `trail`), so a run that
 * changed hands at a gate says so.
 */
final class GraphRuns
{
    /** The event a refused node leaves in the run's log. It matches no transition, so the run does not move. */
    public const string NODE_REFUSED = 'NodeRefused';

    /** The reserved key under which that event says which node was refused, what it needed, and who was driving. */
    public const string REFUSED = '_refused';

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
     * Starts a run and advances it until it finishes, needs somebody, or reaches a node its caller may not run.
     *
     * A refused node is said in the result, never thrown: `ok: false`, the sentence, what was `refused`, and the run
     * parked at that node.
     *
     * @param array<string, mixed> $inputs
     * @param Caller|null          $caller who is starting it, with the authority the surface verified — the nodes up
     *                                     to the first gate run as this caller. Without one, nobody is driving: a
     *                                     node that declares a need does not run
     *
     * @return array{instance_id: string, state: string, awaiting: ?string, ok?: false, error?: string, refused?: array<string, mixed>}
     */
    public function start(string $name, array $inputs, string $requester, ?Caller $caller = null): array
    {
        $graph = $this->register($name);
        $caller ??= new Caller();

        // THE STARTING INPUTS ARE THE GRAPH'S CHANNELS AND NOTHING ELSE. They are the caller's own, and they land in
        // the log everything else is read from — including what the engine reads back about itself. Kept whole, a
        // caller could write that this run is the child of another one (and have it moved by whoever answers this
        // one), that a loop budget is already spent or not yet started (and so decide when a human is asked), or
        // that somebody else ran a node. So only what the graph declared as its state gets in — and no channel can
        // carry a name the engine keeps for itself, because the compiler refuses to declare one.
        $inputs = array_intersect_key($inputs, $graph->channels);

        $inputs['_requester'] = $requester;
        $inputs['_definition'] = $name;

        $instance = ProcessInstance::start($this->store, $graph->definition, $inputs);

        return $this->advance($graph, $instance, $requester, $caller);
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
     * Answers one waiting decision and lets the run continue — as the caller who answered.
     *
     * The node the answer leads to is judged BEFORE the answer is recorded ({@see self::refusalAhead()}): a caller
     * who may not run it has answered nothing, and the gate keeps waiting for somebody who may.
     *
     * @param Caller|null $caller who is answering, with the authority the surface verified — the nodes from here to
     *                            the next gate run as this caller, never as whoever started the run
     *
     * @return array{instance_id: string, state: string, awaiting: ?string, ok?: false, error?: string, refused?: array<string, mixed>}
     *
     * @throws GraphDeclarationException     when the run is not a run of this graph, or is not waiting for a decision
     * @throws SelfApprovalException         when the principal answering is the one that asked
     * @throws TransitionNotAllowedException when the answer is not one of the offered options
     */
    public function decide(string $name, string $instanceId, string $decision, string $principal, ?Caller $caller = null): array
    {
        $graph = $this->register($name);
        $caller ??= new Caller();
        $instance = $this->runOf($graph, $instanceId);

        $waiting = $this->gate->pendingFor($this->store, $instance);
        if ($waiting === null) {
            throw new GraphDeclarationException("Run '{$instanceId}' is not waiting for a decision.");
        }

        $ahead = $this->refusalAhead($graph, $instance, $decision, $caller);
        if ($ahead !== null) {
            return $this->refused($instance, $ahead, $caller);
        }

        $this->gate->resolve($this->store, $instance, $waiting->gateId, $decision, $principal);

        $requester = (string) ($instance->context($this->store)['_requester'] ?? $principal);

        return $this->advance($graph, $instance, $requester, $caller);
    }

    /**
     * Where a run stands, everything it has accumulated, and who ran each of its nodes.
     *
     * The `trail` is read off the log, in order: one row per node that ran or was refused, with `outcome` (`ran` or
     * `refused`), the `node` and its `operation`, and `by` — who was driving the run at that moment
     * ({@see Caller::record()}). A refusal also carries what the node `needs`, and the gate `answer` that was not
     * taken when that is what was refused.
     *
     * @return array{instance_id: string, state: string, awaiting: ?string, context: array<string, mixed>, trail: list<array<string, mixed>>}
     *
     * @throws GraphDeclarationException when the run is not a run of this graph, or does not exist
     */
    public function show(string $name, string $instanceId): array
    {
        $graph = $this->register($name);
        $instance = $this->runOf($graph, $instanceId);

        return $this->positionOf($instance) + [
            'context' => $instance->context($this->store),
            'trail' => $this->trailOf($instance),
        ];
    }

    /**
     * The run, read as a run of the graph it was STARTED as — which is what its own log says, not what the call says.
     *
     * A run is folded against a definition, and which nodes are judged and run are that definition's. Taking the
     * graph from the caller would let a run be answered under another graph with the same gate: that one's nodes
     * judged in place of its own, the gate spent, and the run reading as finished at a node that never ran.
     *
     * @throws GraphDeclarationException when the run was not started as this graph, or does not exist
     */
    private function runOf(CompiledGraph $graph, string $instanceId): ProcessInstance
    {
        $started = $this->store->replay($instanceId)[0] ?? null;

        if ($started === null || $started->type !== 'ProcessStarted' || ($started->payload['_definition'] ?? null) !== $graph->name) {
            throw new GraphDeclarationException("Run '{$instanceId}' is not a run of '{$graph->name}'.");
        }

        return new ProcessInstance($instanceId, $graph->definition);
    }

    /**
     * Lets the run walk as far as this caller can take it, and reports where it came to rest.
     *
     * @return array<string, mixed>
     */
    private function advance(CompiledGraph $graph, ProcessInstance $instance, string $requester, Caller $caller): array
    {
        try {
            $this->runnerFor($graph, $caller)->advance($this->store, $instance, $this->gate, $requester);
        } catch (NodeRefused $refused) {
            return $this->refused($instance, $refused, $caller);
        }

        return $this->positionOf($instance);
    }

    /**
     * The refusal an answer would walk straight into, if any — asked BEFORE the answer is recorded.
     *
     * An answer is an event nobody can take back. Recorded first and refused after, it would spend the gate and
     * leave the run parked at a node its approver may not run, with nothing left to answer: the decision made, its
     * consequence withheld, and no way for somebody who MAY run that node to give the same answer. So the node an
     * answer leads to is judged first, and a caller who may not run it has answered nothing — the gate keeps waiting.
     *
     * Only the first node is looked at: which nodes come after it is decided by what that one returns. Those are
     * judged where every node is judged, in {@see NodeInvoker}, the moment each one is about to run.
     */
    private function refusalAhead(CompiledGraph $graph, ProcessInstance $instance, string $decision, Caller $caller): ?NodeRefused
    {
        foreach ($graph->definition->transitionsFrom($instance->currentState($this->store)) as $transition) {
            if ($transition['name'] !== $decision) {
                continue;
            }

            $node = $graph->operations[$transition['to']] ?? null;
            $why = $node === null ? null : $caller->refusalOf($node);

            return $node === null || $why === null ? null : NodeRefused::ahead($decision, $transition['to'], $node, $why);
        }

        return null; // not an offered option: the gate refuses it by name, as it always did.
    }

    /**
     * A refusal is a line in the run's log and a result — the caller reads the sentence, and where the run stands.
     *
     * The event matches no transition, so the run does not move: it is a fact about the run, not a step of it.
     *
     * @return array<string, mixed>
     */
    private function refused(ProcessInstance $instance, NodeRefused $refused, Caller $caller): array
    {
        $record = [
            'node' => $refused->node,
            'operation' => $refused->operation,
            'needs' => $refused->needs,
            'by' => $caller->record(),
        ];
        if ($refused->answer !== null) {
            $record['answer'] = $refused->answer;
        }

        $this->store->append(new Event($instance->instanceId, self::NODE_REFUSED, [self::REFUSED => $record], $this->store->nextSeq()));

        return ['ok' => false, 'error' => $refused->getMessage()] + $this->positionOf($instance) + ['refused' => $record];
    }

    /**
     * Which node ran or was refused, in order, and who was driving the run each time — read off the log.
     *
     * @return list<array<string, mixed>>
     */
    private function trailOf(ProcessInstance $instance): array
    {
        $trail = [];

        foreach ($this->store->replay($instance->instanceId) as $event) {
            // Read only from the events the engine itself appends: a refusal from its own event, a node's record
            // from a step of the run — never from `ProcessStarted`, whose payload is whatever the run was started with.
            [$key, $outcome] = $event->type === self::NODE_REFUSED ? [self::REFUSED, 'refused'] : [NodeInvoker::RAN, 'ran'];

            if ($event->type !== 'ProcessStarted' && \is_array($event->payload[$key] ?? null)) {
                $trail[] = ['seq' => $event->seq, 'outcome' => $outcome] + $event->payload[$key];
            }
        }

        return $trail;
    }

    private function register(string $name): CompiledGraph
    {
        $graph = $this->graphs->get($name);

        if (!$this->definitions->has($name)) {
            $this->definitions->register($name, $graph->definition);
        }

        return $graph;
    }

    private function runnerFor(CompiledGraph $graph, Caller $caller): ProcessRunner
    {
        return new ProcessRunner($this->dispatcher, $this->definitions, new NodeInvoker($graph, $caller));
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
