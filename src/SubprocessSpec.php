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
 * Declares that a {@see ProcessDefinition} state is a SUBPROCESS state: instead of transitioning
 * automatically or waiting on a human decision behind a {@see
 * \Milpa\Workflow\Entities\GateDefinition}, the process WAITS at this state for an entire OTHER
 * process (`$definitionRef`, resolved through a {@see ProcessDefinitionRegistry} at runtime) to
 * run to ITS OWN terminal state — "a process as a gate of another".
 *
 * {@see ProcessRunner::advance()} treats a subprocess state as a second kind of external-trigger
 * checkpoint, alongside a gate: it starts a FRESH child {@see ProcessInstance} of `$definitionRef`
 * on a brand-new stream (projecting `$inputsMap` from the parent's own context), advances that
 * child to ITS OWN gate or terminal state, and then the PARENT itself stops — it does not
 * auto-advance past this state. When the child later reaches a terminal state, {@see
 * ProcessRunner} routes its outcome back to the parent (see that class's own docblock for the
 * exact event-typing convention this requires the parent's OUTGOING transitions from this state
 * to follow), carrying only the `$outputs` keys declared here.
 */
final readonly class SubprocessSpec
{
    /**
     * @param string                $definitionRef the child {@see ProcessDefinition}'s registered
     *                                             name — resolved through a {@see
     *                                             ProcessDefinitionRegistry} the moment this state
     *                                             is entered, not at definition-build time
     * @param array<string, string> $inputsMap     child input key => parent context key; the
     *                                             child's starting inputs are built by reading
     *                                             each named key out of the PARENT's own
     *                                             accumulated context (a missing parent key yields
     *                                             `null` for that child input, never an error)
     * @param list<string>          $outputs       parent-context keys to copy from the child's
     *                                             TERMINAL context into the routed outcome event's
     *                                             `outputs` payload once the child finishes — only
     *                                             these are ever exposed to the parent; anything
     *                                             else the child accumulated along the way stays
     *                                             private to it
     */
    public function __construct(
        public string $definitionRef,
        public array $inputsMap = [],
        public array $outputs = [],
    ) {
    }
}
