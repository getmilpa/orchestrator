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

namespace Milpa\Orchestrator\Operations;

use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;
use Milpa\Orchestrator\Declaration\GraphRuns;

/** Every decision waiting for a human, across every run of every declared graph. */
#[Operation(name: 'graph:pending', description: 'The decisions waiting for a human, across every run.')]
#[Reads]
final readonly class PendingDecisions
{
    public function __construct()
    {
    }

    /**
     * Everything waiting for a person right now.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs): array
    {
        $pending = $runs->pending();

        return ['count' => \count($pending), 'pending' => $pending];
    }
}
