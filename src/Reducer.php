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

/**
 * Folds a process instance's events into its current {@see ProcessState}. Pure: given the same
 * events and the same definition it always returns the same state — no I/O, no mutable state of
 * its own. This is the mechanism behind "state is a projection of the log": nothing in the
 * orchestrator stores `current_state` directly, everything asks the reducer to derive it.
 */
final class Reducer
{
    /**
     * Starts at `$definition->initialState()` and, for every event in order, looks up the
     * transitions available from the current state and advances to the one whose `name` equals
     * the event's `type`. An event that matches no available transition leaves the state
     * unchanged. Every event's `payload` is merged into the accumulated context regardless of
     * whether it matched a transition, so bootstrap/audit events (e.g. `ProcessStarted`) can carry
     * context without needing a transition of their own.
     *
     * @param list<Event> $events in the order they should be folded (ascending `seq`)
     */
    public function apply(array $events, DefinitionContract $definition): ProcessState
    {
        $state = $definition->initialState();
        $context = [];

        foreach ($events as $event) {
            $context = array_merge($context, $event->payload);

            $transition = $this->matchTransition($event, $definition->transitionsFrom($state));
            if ($transition !== null) {
                $state = $transition['to'];
            }
        }

        return new ProcessState($state, $context);
    }

    /**
     * @param list<array{name: string, to: string}> $transitions
     *
     * @return array{name: string, to: string}|null
     */
    private function matchTransition(Event $event, array $transitions): ?array
    {
        foreach ($transitions as $transition) {
            if ($transition['name'] === $event->type) {
                return $transition;
            }
        }

        return null;
    }
}
