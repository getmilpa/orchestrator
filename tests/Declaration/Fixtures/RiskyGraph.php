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

/** A graph whose only node demands confirmation — refused until the consent gate is compiled. */
#[Graph(name: 'lab:risky-graph', description: 'One node, and it demands a human.')]
#[Start(Risky::class)]
final readonly class RiskyGraph
{
    public function __construct(public string $title)
    {
    }
}
