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

/**
 * How a state DOES something before the machine leaves it.
 *
 * Until this seam existed, {@see ProcessRunner::advance()} walked an automated state by appending
 * its single outgoing transition with an empty payload: the machine moved, but nothing ran. That is
 * the right shape for an approval workflow, where the work happens in the world and the process only
 * records who agreed to it. It is the wrong shape for a graph of agents, where the node IS the work.
 *
 * An implementation is handed the state the machine is standing on and the transitions leaving it,
 * and answers with the transition to take and what to write into the log. Returning `null` means
 * «no node is bound here» and the runner keeps its original behaviour, unchanged — which is why
 * every process that predates this seam behaves exactly as it did.
 *
 * What an implementation must NOT do is decide anything the declaration did not say: the returned
 * transition has to be one of `$transitions`, and the runner refuses it otherwise.
 */
interface NodeInvokerInterface
{
    /**
     * Runs whatever is bound to this state, and says which way out it takes.
     *
     * @param string                                $state       the state the machine is standing on
     * @param array<string, mixed>                  $context     the instance's folded context
     * @param list<array{name: string, to: string}> $transitions the transitions leaving `$state`
     *
     * @return array{0: string, 1: array<string, mixed>}|null the transition to take and the payload
     *                                                        to append, or `null` when no node is
     *                                                        bound to this state
     */
    public function invoke(string $state, array $context, array $transitions): ?array;
}
