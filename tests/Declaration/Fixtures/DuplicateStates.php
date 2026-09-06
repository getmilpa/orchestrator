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

namespace Milpa\Orchestrator\Tests\Declaration\Fixtures;

use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Start;
use Milpa\Orchestrator\Declaration\Edge;

/** Two nodes whose operation names collide after the colon. */
#[Graph(name: 'lab:duplicate', description: 'Two nodes, one state.')]
#[Start(Plain::class)]
#[Edge(from: Plain::class, to: Twin::class)]
final readonly class DuplicateStates
{
    public function __construct(public string $title)
    {
    }
}
