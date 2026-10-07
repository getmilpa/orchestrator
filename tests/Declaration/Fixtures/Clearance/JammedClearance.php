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

namespace Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance;

use Milpa\Command\Declaration\Because;
use Milpa\Command\Declaration\Target;
use Milpa\Orchestrator\Declaration\Edge;
use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Start;

/** A run whose second node declares a need and, once somebody may run it, throws. */
#[Graph(name: 'memo:jammed', description: 'Prepare a memo, print it on a press that jams, shelve it.')]
#[Start(Prepare::class)]
#[Edge(from: Prepare::class, to: Jam::class)]
#[Edge(from: Jam::class, to: Shelve::class)]
final readonly class JammedClearance
{
    public function __construct(
        #[Target]
        #[Because('The memo being cleared — the human names it')]
        public string $title,
        public string $preparedBy = '',
        public string $shelvedBy = '',
    ) {
    }
}
