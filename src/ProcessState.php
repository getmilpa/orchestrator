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
 * The projection a {@see Reducer} folds a process instance's events into: the state it currently
 * occupies, and the accumulated context (e.g. domain ids stamped at start) carried by those
 * events. Never stored — always recomputed from the log, so two instances folding the same events
 * always agree.
 */
final readonly class ProcessState
{
    /**
     * @param string              $currentState the state name this projection landed on
     * @param array<string,mixed> $context      accumulated event payload data (later keys override earlier ones)
     */
    public function __construct(
        public string $currentState,
        public array $context,
    ) {
    }
}
