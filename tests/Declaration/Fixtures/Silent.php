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

use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;

/** Declares that it routes on a Verdict and then returns none. */
#[Operation(name: 'lab:silent', description: 'Says it decides, then does not.')]
#[Reads]
final readonly class Silent
{
    public function __construct(public string $title)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        return ['title' => 'nothing decided'];
    }
}
