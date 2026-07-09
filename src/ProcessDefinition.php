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

use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;

/**
 * A process definition: a set of `milpa/workflow` {@see StateDefinition}s connected by
 * {@see TransitionDefinition}s, one of them flagged `isInitial`. Implements {@see
 * DefinitionContract} so a {@see Reducer} can fold events against it directly, while also
 * exposing the richer workflow entities (states, raw transitions, gates) a caller composing an
 * actual process — or {@see HumanGate} — needs.
 *
 * `initialState()` is derived from `StateDefinition::isInitial()` (exactly one state must carry
 * it) rather than accepted as a separate constructor argument, so there is exactly one source of
 * truth for "where does this process start".
 *
 * At construction, every transition's `fromState`/`toState` must resolve to a state passed in,
 * and the definition is validated acyclic among UNGATED transitions only: a `GateDefinition`
 * attached to a state's outgoing transitions marks that state a human decision point, and a
 * decision point breaks any loop through it (see {@see self::assertAcyclicAmongUngatedTransitions()}
 * for why a full-graph acyclic check would incorrectly reject a `review_gate --reject--> draft
 * --submit--> review_gate` shape, which is a legitimate revise-and-resubmit loop).
 */
final class ProcessDefinition implements DefinitionContract
{
    /** @var array<string, StateDefinition> keyed by state code, insertion order preserved */
    private array $states = [];

    /** @var array<string, list<TransitionDefinition>> keyed by the transition's fromState code */
    private array $transitionsByFromState = [];

    private readonly string $initialState;

    /**
     * @param list<StateDefinition>      $states      every state this process can occupy; exactly
     *                                                one must have `isInitial() === true`
     * @param list<TransitionDefinition> $transitions every transition between two of `$states`
     *
     * @throws \RuntimeException on a duplicate state code, a transition referencing an unknown
     *                           state, a state count other than exactly-one-initial, or a cycle
     *                           among ungated transitions
     */
    public function __construct(array $states, array $transitions)
    {
        foreach ($states as $state) {
            $code = $state->getCode();
            if (isset($this->states[$code])) {
                throw new \RuntimeException("ProcessDefinition: duplicate state code '{$code}'.");
            }
            $this->states[$code] = $state;
        }

        $initialCandidates = array_values(array_filter(
            $this->states,
            static fn (StateDefinition $s): bool => $s->isInitial(),
        ));
        if (\count($initialCandidates) !== 1) {
            throw new \RuntimeException(sprintf(
                'ProcessDefinition must have exactly one initial state, found %d.',
                \count($initialCandidates),
            ));
        }
        $this->initialState = $initialCandidates[0]->getCode();

        foreach ($transitions as $transition) {
            $from = $transition->getFromState()->getCode();
            $to = $transition->getToState()->getCode();

            if (!isset($this->states[$from])) {
                throw new \RuntimeException("ProcessDefinition: transition '{$transition->getCode()}' references unknown fromState '{$from}'.");
            }
            if (!isset($this->states[$to])) {
                throw new \RuntimeException("ProcessDefinition: transition '{$transition->getCode()}' references unknown toState '{$to}'.");
            }

            $this->transitionsByFromState[$from][] = $transition;
        }

        $this->assertAcyclicAmongUngatedTransitions();
    }

    /**
     * The state a fresh process instance starts in — the one state with `isInitial() === true`.
     */
    public function initialState(): string
    {
        return $this->initialState;
    }

    /**
     * The narrow `{name, to}` shape {@see Reducer} needs, projected down from the real workflow
     * `TransitionDefinition`s: `name` is the transition's `code` (the literal event `type` that
     * advances it — see {@see Reducer::apply()}), `to` is its destination state's code. Unknown
     * `$state` yields an empty list, matching {@see DefinitionContract}'s "no transitions" case.
     *
     * @return list<array{name: string, to: string}>
     */
    public function transitionsFrom(string $state): array
    {
        return array_map(
            static fn (TransitionDefinition $t): array => [
                'name' => $t->getCode(),
                'to' => $t->getToState()->getCode(),
            ],
            $this->transitionsByFromState[$state] ?? [],
        );
    }

    /**
     * The raw `milpa/workflow` transitions leaving `$state`, unprojected — for callers that need
     * more than `{name, to}` (e.g. reading a transition's attached {@see GateDefinition}s
     * directly). Prefer {@see self::transitionsFrom()} or {@see self::gateFor()} where they
     * suffice.
     *
     * @return list<TransitionDefinition>
     */
    public function workflowTransitionsFrom(string $state): array
    {
        return $this->transitionsByFromState[$state] ?? [];
    }

    /**
     * Every state code in this definition, in the order `$states` was passed to the constructor.
     *
     * @return list<string>
     */
    public function states(): array
    {
        return array_keys($this->states);
    }

    /**
     * Whether `$state` is terminal (`StateDefinition::isTerminal()`). An unknown `$state` is
     * treated as not terminal.
     */
    public function isTerminal(string $state): bool
    {
        return ($this->states[$state] ?? null)?->isTerminal() ?? false;
    }

    /**
     * The gate guarding `$state`'s outgoing transitions: the first {@see GateDefinition} found
     * attached to any of `$state`'s transitions, or `null` when none of them carry one. A
     * gated state's transitions are expected to share the SAME gate instance (one human
     * checkpoint per state, however many outcomes it offers) — this is how {@see HumanGate}
     * finds the single gate to open for a process instance sitting at `$state`.
     */
    public function gateFor(string $state): ?GateDefinition
    {
        foreach ($this->transitionsByFromState[$state] ?? [] as $transition) {
            $gate = $transition->getGateDefinitions()->first();
            if ($gate instanceof GateDefinition) {
                return $gate;
            }
        }

        return null;
    }

    /**
     * Guards against a state machine that can loop forever without ever passing through a human
     * decision point. Cycle detection runs ONLY over transitions whose origin state has no
     * attached gate ("ungated" transitions) — a gated state is a decision point, and a human
     * resolving it is what breaks the loop each time, so `draft --submit--> review_gate
     * --reject--> draft` is a legitimate, intentional revise-and-resubmit cycle, not a defect. A
     * cycle entirely among ungated (automatic) transitions, however, has no such escape hatch and
     * is rejected at load time.
     *
     * @throws \RuntimeException when the ungated-transition subgraph contains a cycle
     */
    private function assertAcyclicAmongUngatedTransitions(): void
    {
        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];
        foreach (array_keys($this->states) as $code) {
            $adjacency[$code] = [];
        }

        foreach ($this->transitionsByFromState as $from => $transitions) {
            if ($this->gateFor($from) !== null) {
                continue;
            }
            foreach ($transitions as $transition) {
                $adjacency[$from][] = $transition->getToState()->getCode();
            }
        }

        $visiting = [];
        $visited = [];
        foreach (array_keys($adjacency) as $state) {
            $this->assertNoCycleFrom($state, $adjacency, $visiting, $visited);
        }
    }

    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, true>         $visiting
     * @param array<string, true>         $visited
     */
    private function assertNoCycleFrom(string $state, array $adjacency, array &$visiting, array &$visited): void
    {
        if (isset($visited[$state])) {
            return;
        }
        if (isset($visiting[$state])) {
            throw new \RuntimeException("ProcessDefinition contains a cycle of ungated transitions revisiting state '{$state}'.");
        }

        $visiting[$state] = true;
        foreach ($adjacency[$state] as $next) {
            $this->assertNoCycleFrom($next, $adjacency, $visiting, $visited);
        }
        unset($visiting[$state]);
        $visited[$state] = true;
    }
}
