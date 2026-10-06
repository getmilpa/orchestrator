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

/** A node that needs nothing and asks who is running it — it writes the answer into its channel. */
#[Operation(name: 'memo:prepare', description: 'Prepare the memo for its check.')]
#[Mutates(Mutation::Persistent, rollback: 'memo:unprepare')]
final readonly class Prepare
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
