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

use Milpa\Command\Declaration\Needs;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;
use Milpa\Orchestrator\Declaration\GraphRuns;

/** What this app can run as a graph, and what each one needs to start. */
#[Operation(name: 'graph:list', description: 'The graphs this app declares, with the channels each one starts from.')]
#[Reads]
final readonly class ListGraphs
{
    public function __construct()
    {
    }

    /**
     * The catalogue, as data a human reads and an agent can act on.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs): array
    {
        $graphs = $runs->catalogue();

        return ['count' => \count($graphs), 'graphs' => $graphs];
    }
}
