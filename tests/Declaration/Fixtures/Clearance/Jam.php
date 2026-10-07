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

use Milpa\Command\Declaration\Mutates;
use Milpa\Command\Declaration\Needs;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Effect\Mutation;

/** A node that declares a need and breaks halfway: once it is reached it may have done part of its work. */
#[Operation(name: 'memo:jam', description: 'Print the memo — on a press that jams.')]
#[Mutates(Mutation::Persistent, rollback: 'memo:unprint')]
#[Needs(scopes: ['memo:print'])]
final readonly class Jam
{
    public function __construct(public string $title)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        throw new \RuntimeException('the press jammed');
    }
}
