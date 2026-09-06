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

use Milpa\Command\Declaration\Confirms;
use Milpa\Command\Declaration\Mutates;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Effect\Mutation;

#[Operation(name: 'lab:risky', description: 'Something a human must confirm.')]
#[Mutates(Mutation::Persistent, rollback: 'lab:undo')]
#[Confirms]
final readonly class Risky
{
    public function __construct(public string $title)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        return [];
    }
}
