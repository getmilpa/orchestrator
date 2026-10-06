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

use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;

/** The node that decides. What it finds is scripted by the `clean` channel the run started with. */
#[Operation(name: 'memo:check', description: 'Check whether the memo is clean.')]
#[Reads]
final readonly class Check
{
    public function __construct(public string $title, public bool $clean = false)
    {
    }

    public function run(): Checked
    {
        return new Checked($this->clean ? Finding::Clean : Finding::Dirty);
    }
}
