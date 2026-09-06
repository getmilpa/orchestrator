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

use Milpa\Command\Declaration\Because;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;
use Milpa\Command\Declaration\Target;
use Milpa\Orchestrator\Declaration\GraphRuns;

/** Where one run stands, and everything its nodes have written into it. */
#[Operation(name: 'graph:show', description: 'Where a run stands and everything it has accumulated.')]
#[Reads]
final readonly class ShowGraphRun
{
    public function __construct(
        #[Because('Which graph the run belongs to')]
        public string $graph,
        #[Target]
        #[Because('Which run to read')]
        public string $instance,
    ) {
    }

    /**
     * Where the run stands, and everything its nodes have written into it.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs): array
    {
        return $runs->show($this->graph, $this->instance);
    }
}
