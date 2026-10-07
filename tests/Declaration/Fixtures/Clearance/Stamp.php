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
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\InvocationContext;

/** A node that needs nothing, between an answer and the node that does: it says who ran it. */
#[Operation(name: 'memo:stamp', description: 'Stamp the memo before it is released.')]
#[Mutates(Mutation::Persistent, rollback: 'memo:unstamp')]
final readonly class Stamp
{
    public function __construct(public string $title)
    {
    }

    /** @return array{stampedBy: string} */
    public function run(?InvocationContext $context = null): array
    {
        return ['stampedBy' => $context->actor ?? 'nobody'];
    }
}
