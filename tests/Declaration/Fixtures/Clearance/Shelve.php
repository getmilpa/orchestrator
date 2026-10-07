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

/** The way out that needs nothing: any answering human may shelve. */
#[Operation(name: 'memo:shelve', description: 'Shelve the memo unreleased.')]
#[Mutates(Mutation::Persistent, rollback: 'memo:unshelve')]
final readonly class Shelve
{
    public function __construct(public string $title)
    {
    }

    /** @return array{shelvedBy: string} */
    public function run(?InvocationContext $context = null): array
    {
        return ['shelvedBy' => $context->actor ?? 'nobody'];
    }
}
