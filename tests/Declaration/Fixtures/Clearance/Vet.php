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
use Milpa\Command\InvocationContext;

/** The first node of a run, and it declares a need: whoever cannot vet a memo parks the run before any gate. */
#[Operation(name: 'memo:vet', description: 'Vet the memo before anybody checks it.')]
#[Mutates(Mutation::Persistent, rollback: 'memo:unvet')]
#[Needs(scopes: ['memo:vet'])]
final readonly class Vet
{
    public function __construct(public string $title)
    {
    }

    /** @return array{preparedBy: string} */
    public function run(?InvocationContext $context = null): array
    {
        return ['preparedBy' => $context->actor ?? 'nobody'];
    }
}
