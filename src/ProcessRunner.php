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

namespace Milpa\Orchestrator;

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * Drives a process instance forward automatically until it either reaches a terminal state or a
 * state that needs a human decision. {@see Reducer} only APPLIES events already in the log —
 * something has to decide WHICH automated transition event to append next, and whether a gated
 * state's gate is already open before opening it again; `ProcessRunner` is that driver.
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
 * inside a tool.
 */
final class ProcessRunner
{
    private const string TERMINAL_MARKER = 'ProcessTerminalReached';

    public function __construct(
        private readonly MilpaEventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Advances `$instance` for as long as its current state is automated (has no gate): appends
     * that state's single outgoing transition as the advancing {@see Event} (payload `{}`) and
     * loops. Stops the moment the current state is either:
     *
     *  - terminal ({@see ProcessDefinition::isTerminal()}) — fires the terminal seam (see the
     *    class docblock) and returns, or
     *  - gated ({@see ProcessDefinition::gateFor()} non-null) — a human decision is needed; opens
     *    the gate via {@see HumanGate::openFor()} UNLESS {@see HumanGate::pendingFor()} already
     *    finds an unresolved `GateOpened` for it, so calling `advance()` again on an
     *    already-awaiting instance never appends a redundant `GateOpened` event.
     *
     * @param string $requester the principal {@see HumanGate::openFor()} should record as the
     *                          requester of any gate this call opens
     */
    public function advance(
        EventStoreInterface $store,
        ProcessInstance $instance,
        HumanGate $gate,
        string $requester,
    ): void {
        $definition = $instance->definition;

        while (true) {
            $state = $instance->currentState($store);

            if ($definition->isTerminal($state)) {
                $this->fireTerminalOnce($store, $instance, $state);

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
     */
    private function fireTerminalOnce(EventStoreInterface $store, ProcessInstance $instance, string $state): void
    {
        foreach ($store->replay($instance->instanceId) as $event) {
            if ($event->type === self::TERMINAL_MARKER) {
                return;
            }
        }

        $store->append(new Event($instance->instanceId, self::TERMINAL_MARKER, ['state' => $state], $store->nextSeq()));

        $this->dispatcher->dispatch('process.terminal', [
            'instance_id' => $instance->instanceId,
            'final_state' => $state,
            'context' => $instance->context($store),
        ]);
    }
}
