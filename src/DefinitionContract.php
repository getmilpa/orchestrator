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

/**
 * The narrow slice of a process definition {@see Reducer} needs to fold events into a
 * {@see ProcessState}: where a process starts, and which transitions are available from a given
 * state. Deliberately smaller than a full process definition (states, terminality, gates, inputs)
 * — {@see ProcessDefinition} (composing `milpa/workflow`'s `StateDefinition`/`TransitionDefinition`)
 * implements this contract on top of its richer model, but any other state-machine representation
 * can drive {@see Reducer} the same way by implementing this interface directly.
 */
interface DefinitionContract
{
    /** The state a fresh process instance starts in. */
    public function initialState(): string;

    /**
     * The transitions available from `$state`, each naming the event `type` that triggers it and
     * the state it leads to. An empty list means `$state` has no outgoing transitions (e.g. it is
     * terminal, or simply has none defined from it).
     *
     * @return list<array{name: string, to: string}>
     */
    public function transitionsFrom(string $state): array;
}
