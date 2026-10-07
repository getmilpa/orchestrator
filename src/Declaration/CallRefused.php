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

/**
 * The house understood the call and refuses it, because of what it names or where the run stands.
 *
 * A graph this app does not declare; a run that is not a run of the graph it was named under, or does not exist; a
 * run that is not waiting for the decision it was sent, or is waiting and was asked to resume; an answer the gate
 * never offered. None of them is a failure: nothing broke, nothing was written, and the message is the sentence the
 * caller needs.
 *
 * {@see GraphRuns} throws it, for a host that drives the engine itself. The `graph:*` operations ANSWER it — `ok:
 * false` and this sentence — because a surface treats a thrown refusal as a failure: over HTTP it is a 500 whose
 * message stays in the log, and the caller reads `internal_error` (greenhouse decisions/0583).
 *
 * It stays a {@see GraphDeclarationException}, which is what these refusals were thrown as before they had a name of
 * their own — so whoever caught that still catches this. The reverse is the point: a graph that does not COMPILE is
 * a GraphDeclarationException that is NOT this, and nobody answers for it.
 */
final class CallRefused extends GraphDeclarationException
{
    /**
     * What an operation answers for it: a negative verdict and the sentence — nothing the call did not earn.
     *
     * @return array{ok: false, error: string}
     */
    public function answer(): array
    {
        return ['ok' => false, 'error' => $this->getMessage()];
    }
}
