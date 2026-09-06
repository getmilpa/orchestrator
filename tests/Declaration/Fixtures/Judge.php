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

/** A node that decides, for the negative fixtures. */
#[Operation(name: 'lab:judge', description: 'Decide.')]
#[Reads]
final readonly class Judge
{
    public function __construct(public string $title)
    {
    }

    public function run(): GradeReport
    {
        return new GradeReport('because', Verdict::Passed);
    }
}
