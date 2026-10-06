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

/** A channel under a name the engine keeps for itself: seeded or written by a node, it would move a loop budget. */
#[Start(Plain::class)]
#[Graph(name: 'lab:reserved', description: 'A graph that declares the budget counter as its own state.')]
final readonly class ReservedChannel
{
    /** @param array<string, int> $_taken */
    public function __construct(public string $title, public array $_taken = [])
    {
    }
}
