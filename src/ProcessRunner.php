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

namespace Milpa\Orchestrator;

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Support\UuidGenerator;

/**
 * Drives a process instance forward automatically until it either reaches a terminal state or a
 * state that needs an external trigger: a human decision (a gate) or a whole child process (a
 * {@see SubprocessSpec}). {@see Reducer} only APPLIES events already in the log — something has
 * to decide WHICH automated transition event to append next, whether a gated state's gate is
 * already open before opening it again, and whether a subprocess state's child has already been
 * started before starting another one; `ProcessRunner` is that driver.
 *
 * Every step re-reads `$instance->currentState($store)` fresh (never cached) — "state is a
 * projection", the same invariant every other class in this engine holds.
 *
 * **The terminal seam.** When a run lands on a terminal state, this class does NOT run any
 * domain side effect itself (it is process-definition-agnostic — it has no idea what "reaching
 * `published`" should DO to a domain entity). Instead it appends a `ProcessTerminalReached` audit
 * event (idempotency marker — see {@see self::fireTerminalOnce()}) and, the first time only,
 * dispatches a `process.terminal` event via the injected {@see MilpaEventDispatcherInterface}
 * with payload `{instance_id, final_state, context}`. A consumer subscribes to `process.terminal`
 * to run whatever domain effect reaching that terminal state should trigger (e.g. publishing a
 * post) — see the class docblock's own "terminal seam" note for why this lives here rather than
 * inside a tool. The SAME `ProcessTerminalReached` marker also guards subprocess ROUTING (below)
 * from firing twice — reaching terminal happens exactly once per instance, ever, and everything
 * that happens as a consequence of it is tied to that single occurrence.
 *
 * **The subprocess seam — entering.** When `advance()` finds the current state is a subprocess
 * state (`$definition->subprocessFor($state) !== null`), it resolves the child {@see
 * ProcessDefinition} by name through the injected {@see ProcessDefinitionRegistry}, builds the
 * child's starting inputs by projecting `SubprocessSpec::$inputsMap` out of the PARENT's own
 * context, and {@see ProcessInstance::start()}s the child on a brand-new stream — stamping
 * `parent_instance_id`, `parent_state`, and a fresh `correlation_id` into the child's
 * `ProcessStarted` payload (mirroring how {@see \Milpa\Orchestrator\Tools\ProcessInstantiateTool}
 * stamps `_requester`/`_definition`; this method stamps THOSE two on the child as well, so the
 * child is fully discoverable through the same tool-layer conventions — including by
 * `process_list_pending_approvals`, at any nesting depth). It then recursively advances the CHILD
 * to ITS OWN gate or terminal state. The PARENT itself always stops the moment it enters a
 * subprocess state — see {@see self::enterSubprocessOnce()}'s idempotency guard for why calling
 * `advance()` again on an already-waiting parent never starts a second child.
 *
 * **The subprocess seam — routing back.** When a CHILD instance (one carrying `parent_instance_id`
 * in its context) reaches ITS OWN terminal state, {@see self::fireTerminalOnce()} — after firing
 * `process.terminal` for the child's own domain effects — additionally appends an event to the
 * PARENT's stream and advances the parent again (see {@see self::routeSubprocessDoneToParent()}).
 * That appended event's `type` is the child's OUTCOME (its terminal state's code) — NOT the
 * literal string `subprocess_done` — so it satisfies {@see Reducer}'s unchanged
 * `event.type === transition.name` matching rule with zero changes to `Reducer` or {@see
 * DefinitionContract}: **a subprocess state's outgoing transitions in the PARENT definition must
 * be named after the child's possible outcome(s), exactly like a gate's outgoing transitions are
 * named after its decision options.** The payload nonetheless carries `subprocess_done: true`
 * (plus `outcome`, `child_instance_id`, and the declared `outputs`) so the routing event is
 * unambiguously identifiable by payload for audit/testing purposes even though its wire `type`
 * varies per outcome. Because this routing recursively calls `advance()` on the parent, and a
 * parent reaching ITS OWN terminal state triggers the exact same routing again if IT is itself a
 * subprocess child, the chain recurses all the way up to the root — bounded by {@see
 * self::MAX_SUBPROCESS_DEPTH}.
 */
final class ProcessRunner
{
    use UuidGenerator;

    private const string TERMINAL_MARKER = 'ProcessTerminalReached';
    private const string SUBPROCESS_STARTED_MARKER = 'SubprocessStarted';

    /**
     * Runtime safety net, independent of {@see ProcessDefinitionRegistry}'s static
     * definition-reference cycle check: the deepest a chain of subprocess instances may nest
     * before {@see self::advance()} refuses to go further. Guards against a mis-declared graph
     * that slips past the static check (e.g. a definition mutated after registration) spinning
     * forever instead of failing loudly.
     */
    public const int MAX_SUBPROCESS_DEPTH = 10;

    public function __construct(
        private readonly MilpaEventDispatcherInterface $dispatcher,
        private readonly ?ProcessDefinitionRegistry $registry = null,
    ) {
    }

    /**
     * Advances `$instance` for as long as its current state is automated (has no gate and is not
     * a subprocess state): appends that state's single outgoing transition as the advancing
     * {@see Event} (payload `{}`) and loops. Stops the moment the current state is one of:
     *
     *  - terminal ({@see ProcessDefinition::isTerminal()}) — fires the terminal seam (see the
     *    class docblock) and returns;
     *  - a subprocess state ({@see ProcessDefinition::subprocessFor()} non-null) — starts the
     *    child process UNLESS it was already started for this visit to the state (see {@see
     *    self::enterSubprocessOnce()}) and returns; the parent never auto-advances past this
     *    state on its own — only a routed `subprocess_done` (see the class docblock) moves it on;
     *  - gated ({@see ProcessDefinition::gateFor()} non-null) — a human decision is needed; opens
     *    the gate via {@see HumanGate::openFor()} UNLESS {@see HumanGate::pendingFor()} already
     *    finds an unresolved `GateOpened` for it, so calling `advance()` again on an
     *    already-awaiting instance never appends a redundant `GateOpened` event.
     *
     * @param string $requester the principal {@see HumanGate::openFor()} should record as the
     *                          requester of any gate this call opens, and the `_requester` this
     *                          call stamps on any subprocess child it starts
     * @param int    $depth     current subprocess nesting depth; callers should omit this
     *                          (defaults to `0`) — it is incremented on every recursive call this
     *                          class makes into itself, whether descending into a fresh child or
     *                          routing back up to a parent, and checked against {@see
     *                          self::MAX_SUBPROCESS_DEPTH} at the top of every call
     *
     * @throws \RuntimeException when `$depth` exceeds {@see self::MAX_SUBPROCESS_DEPTH}, when a
     *                           subprocess state is entered without a {@see
     *                           ProcessDefinitionRegistry} configured, or when a terminal
     *                           instance's `parent_instance_id` cannot be resolved back to a
     *                           registered definition
     */
    public function advance(
        EventStoreInterface $store,
        ProcessInstance $instance,
        HumanGate $gate,
        string $requester,
        int $depth = 0,
    ): void {
        if ($depth > self::MAX_SUBPROCESS_DEPTH) {
            throw new \RuntimeException(sprintf(
                "ProcessRunner: subprocess nesting depth exceeded %d levels advancing instance '%s' — check the process-definition graph for a mis-declared cycle.",
                self::MAX_SUBPROCESS_DEPTH,
                $instance->instanceId,
            ));
        }

        $definition = $instance->definition;

        while (true) {
            $state = $instance->currentState($store);

            if ($definition->isTerminal($state)) {
                $this->fireTerminalOnce($store, $instance, $state, $gate, $requester, $depth);

                return;
            }

            $subprocess = $definition->subprocessFor($state);
            if ($subprocess !== null) {
                $this->enterSubprocessOnce($store, $instance, $state, $subprocess, $gate, $requester, $depth);

                return;
            }

            if ($definition->gateFor($state) !== null) {
                if ($gate->pendingFor($store, $instance) === null) {
                    $gate->openFor($store, $instance, $requester);
                }

                return;
            }

            $transitions = $definition->transitionsFrom($state);
            if ($transitions === []) {
                // Defensive: a non-terminal, ungated state with no outgoing transition is a dead
                // end no definition built against this engine's invariants should produce, but
                // looping forever (or throwing) would both be worse than simply stopping here.
                return;
            }

            $store->append(new Event($instance->instanceId, $transitions[0]['name'], [], $store->nextSeq()));
        }
    }

    /**
     * Appends the idempotency marker and dispatches `process.terminal` the FIRST time
     * `$instance` is found at a terminal state — a marker already present in the log (from an
     * earlier call to {@see self::advance()}, possibly from a different `ProcessRunner`
     * instance) means this is a no-op, so `process.terminal` fires exactly once per instance no
     * matter how many times `advance()` is subsequently called on an already-terminal instance.
     * The SAME guard covers {@see self::routeSubprocessDoneToParent()}, called right after —
     * routing to a parent (if any) is therefore also a strict one-time occurrence.
     */
    private function fireTerminalOnce(
        EventStoreInterface $store,
        ProcessInstance $instance,
        string $state,
        HumanGate $gate,
        string $requester,
        int $depth,
    ): void {
        foreach ($store->replay($instance->instanceId) as $event) {
            if ($event->type === self::TERMINAL_MARKER) {
                return;
            }
        }

        $store->append(new Event($instance->instanceId, self::TERMINAL_MARKER, ['state' => $state], $store->nextSeq()));

        $context = $instance->context($store);

        $this->dispatcher->dispatch('process.terminal', [
            'instance_id' => $instance->instanceId,
            'final_state' => $state,
            'context' => $context,
        ]);

        $this->routeSubprocessDoneToParent($store, $instance, $state, $context, $gate, $requester, $depth);
    }

    /**
     * Starts `$spec`'s child process for `$instance` sitting at subprocess state `$state`, UNLESS
     * a `SubprocessStarted` marker for this exact state already exists on `$instance`'s own
     * stream (idempotency — mirrors {@see self::fireTerminalOnce()}'s marker check, so calling
     * {@see self::advance()} again on an already-waiting parent is a total no-op here).
     *
     * Builds the child's starting inputs by projecting `$spec->inputsMap` out of `$instance`'s
     * own context, stamps `_definition`/`_requester` (the SAME tool-layer convention {@see
     * \Milpa\Orchestrator\Tools\ProcessInstantiateTool} uses) plus `parent_instance_id`,
     * `parent_state`, and a fresh `correlation_id`, starts the child via {@see
     * ProcessInstance::start()}, records a `SubprocessStarted` marker on the PARENT's own stream,
     * then recursively {@see self::advance()}s the child one level deeper.
     *
     * @throws \RuntimeException when no {@see ProcessDefinitionRegistry} was configured to
     *                           resolve `$spec->definitionRef`
     */
    private function enterSubprocessOnce(
        EventStoreInterface $store,
        ProcessInstance $instance,
        string $state,
        SubprocessSpec $spec,
        HumanGate $gate,
        string $requester,
        int $depth,
    ): void {
        foreach ($store->replay($instance->instanceId) as $event) {
            if ($event->type === self::SUBPROCESS_STARTED_MARKER && ($event->payload['state'] ?? null) === $state) {
                return;
            }
        }

        if ($this->registry === null) {
            throw new \RuntimeException(sprintf(
                "ProcessRunner: instance '%s' entered subprocess state '%s' but no ProcessDefinitionRegistry was configured to resolve '%s'.",
                $instance->instanceId,
                $state,
                $spec->definitionRef,
            ));
        }

        $childDefinition = $this->registry->get($spec->definitionRef);

        $parentContext = $instance->context($store);
        $childInputs = [];
        foreach ($spec->inputsMap as $childKey => $parentKey) {
            $childInputs[$childKey] = $parentContext[$parentKey] ?? null;
        }

        $correlationId = self::generateUuid();
        $childInputs['_definition'] = $spec->definitionRef;
        $childInputs['_requester'] = $requester;
        $childInputs['parent_instance_id'] = $instance->instanceId;
        $childInputs['parent_state'] = $state;
        $childInputs['correlation_id'] = $correlationId;

        $childInstance = ProcessInstance::start($store, $childDefinition, $childInputs);

        $store->append(new Event($instance->instanceId, self::SUBPROCESS_STARTED_MARKER, [
            'state' => $state,
            'child_instance_id' => $childInstance->instanceId,
            'child_definition' => $spec->definitionRef,
            'correlation_id' => $correlationId,
        ], $store->nextSeq()));

        $this->advance($store, $childInstance, $gate, $requester, $depth + 1);
    }

    /**
     * Routes `$instance`'s terminal outcome to its parent, if it has one — a no-op when
     * `$context['parent_instance_id']` is absent (an ordinary, non-subprocess terminal instance).
     *
     * Resolves the parent's {@see ProcessDefinition} through the injected {@see
     * ProcessDefinitionRegistry} by reading the `_definition` marker off the parent stream's own
     * `ProcessStarted` event (the same convention {@see
     * \Milpa\Orchestrator\Tools\ResolvesDefinitionNameTrait} uses — every instance this class
     * itself starts via {@see self::enterSubprocessOnce()} carries it, and so does every instance
     * started through {@see \Milpa\Orchestrator\Tools\ProcessInstantiateTool}). Appends an event
     * to the PARENT's stream whose `type` is `$outcome` (see the class docblock for exactly why)
     * and whose payload carries `subprocess_done: true`, `child_instance_id`, `outcome`, and the
     * `outputs` declared by the parent definition's {@see SubprocessSpec} for the state the
     * parent was waiting at — then recursively {@see self::advance()}s the parent, using the
     * parent's OWN recorded `_requester` when available (falling back to `$requester`), exactly
     * like {@see \Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool} does when re-advancing after
     * a decision.
     *
     * @param array<string, mixed> $context `$instance`'s own terminal context
     *
     * @throws \RuntimeException when `$instance` carries a `parent_instance_id` but either no
     *                           {@see ProcessDefinitionRegistry} was configured, or the parent's
     *                           definition cannot be resolved from its stream
     */
    private function routeSubprocessDoneToParent(
        EventStoreInterface $store,
        ProcessInstance $instance,
        string $outcome,
        array $context,
        HumanGate $gate,
        string $requester,
        int $depth,
    ): void {
        $parentInstanceId = $context['parent_instance_id'] ?? null;
        if (!is_string($parentInstanceId) || $parentInstanceId === '') {
            return;
        }

        if ($this->registry === null) {
            throw new \RuntimeException(sprintf(
                "ProcessRunner: instance '%s' has parent_instance_id '%s' but no ProcessDefinitionRegistry was configured to resolve the parent's definition.",
                $instance->instanceId,
                $parentInstanceId,
            ));
        }

        $parentDefinitionName = $this->definitionNameFor($store, $parentInstanceId);
        if ($parentDefinitionName === null) {
            throw new \RuntimeException(sprintf(
                "ProcessRunner: cannot resolve the process definition governing parent instance '%s' (no '_definition' marker on its ProcessStarted event).",
                $parentInstanceId,
            ));
        }

        $parentDefinition = $this->registry->get($parentDefinitionName);
        $parentInstance = new ProcessInstance($parentInstanceId, $parentDefinition);
        $parentState = (string) ($context['parent_state'] ?? '');

        $outputs = [];
        $spec = $parentDefinition->subprocessFor($parentState);
        if ($spec !== null) {
            foreach ($spec->outputs as $key) {
                if (array_key_exists($key, $context)) {
                    $outputs[$key] = $context[$key];
                }
            }
        }

        $store->append(new Event($parentInstanceId, $outcome, [
            'subprocess_done' => true,
            'child_instance_id' => $instance->instanceId,
            'outcome' => $outcome,
            'outputs' => $outputs,
        ], $store->nextSeq()));

        $parentRequester = (string) ($parentInstance->context($store)['_requester'] ?? $requester);

        $this->advance($store, $parentInstance, $gate, $parentRequester, $depth + 1);
    }

    /**
     * The `_definition` value stamped into `$streamId`'s `ProcessStarted` event, or `null` when
     * the stream has no such bootstrap event. Duplicated from {@see
     * \Milpa\Orchestrator\Tools\ResolvesDefinitionNameTrait} on purpose — that trait lives in the
     * `Tools` namespace, and this core engine class should not depend on the tool layer.
     */
    private function definitionNameFor(EventStoreInterface $store, string $streamId): ?string
    {
        $events = $store->replay($streamId);
        $first = $events[0] ?? null;
        if ($first === null || $first->type !== 'ProcessStarted') {
            return null;
        }

        $name = $first->payload['_definition'] ?? null;

        return is_string($name) ? $name : null;
    }
}
