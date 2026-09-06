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

/** A cycle with no budget and no gate: the engine's own refusal, surfaced as a graph finding. */
#[Graph(name: 'lab:unbudgeted', description: 'A loop with no escape.')]
#[Start(Plain::class)]
#[Edge(from: Plain::class, to: Second::class)]
#[Edge(from: Second::class, to: Plain::class)]
final readonly class Unbudgeted
{
    public function __construct(public string $title)
    {
    }
}
