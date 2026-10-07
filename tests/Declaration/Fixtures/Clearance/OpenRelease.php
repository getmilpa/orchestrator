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

/** A release that declares no need — and compiles to the same state, `release`, as the one that does. */
#[Operation(name: 'open:release', description: 'Release a note that needs nobody in particular.')]
#[Mutates(Mutation::Persistent, rollback: 'open:recall')]
final readonly class OpenRelease
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
