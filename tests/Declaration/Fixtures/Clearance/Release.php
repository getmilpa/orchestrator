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
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\InvocationContext;

/** The node that spends authority: it declares a scope, and it too writes who ran it. */
#[Operation(name: 'memo:release', description: 'Release the memo to everyone.')]
#[Mutates(
    Mutation::Persistent,
    Externality::Public,
    Reversibility::Compensatable,
    subject: Subject::Data,
    rollback: 'memo:recall',
)]
#[Needs(scopes: ['memo:release'])]
final readonly class Release
{
    public function __construct(public string $title)
    {
    }

    /** @return array{releasedBy: string} */
    public function run(?InvocationContext $context = null): array
    {
        return ['releasedBy' => $context->actor ?? 'nobody'];
    }
}
