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

namespace Milpa\Orchestrator\Event;

use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Orchestrator\ProcessRunner;

/**
 * Every event this package dispatches, declared by the package itself (greenhouse decisions/0228).
 *
 * The emitter is the authority on «what events exist», and the dispatcher is the one place every
 * dispatch passes through — so {@see ProcessRunner} hands these declarations to the dispatcher the
 * moment it receives one, and the house counts them from there. The names below are the SAME
 * constants the `dispatch()` sites use: a declaration that retyped the string could drift from
 * the dispatch it describes, and nothing would notice.
 */
final class OrchestratorEvents
{
    /**
     * Dispatched by {@see ProcessRunner} the first time a process instance is found at a terminal
     * state, with payload `{instance_id, final_state, context}` — see the runner's class docblock
     * for the terminal seam this event is.
     */
    public const string PROCESS_TERMINAL = 'process.terminal';

    /**
     * One declaration per event name this package dispatches, in the order they are listed here.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: self::PROCESS_TERMINAL,
                dispatchedBy: ProcessRunner::class,
                when: 'A process instance is found at a terminal state for the first time, right after its ProcessTerminalReached marker is appended.',
                subjectKey: 'instance_id',
                subjectType: 'string',
            ),
        ];
    }
}
