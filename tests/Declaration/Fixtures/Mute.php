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

/** Its node is not an operation at all. */
#[Graph(name: 'lab:mute', description: 'Its node declares nothing.')]
#[Start(Writer::class)]
final readonly class Mute
{
    public function __construct(public string $title)
    {
    }
}
