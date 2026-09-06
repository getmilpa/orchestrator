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

use Milpa\Command\Declaration\Mutates;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Effect\Mutation;

#[Operation(name: 'essay:abandon', description: 'Close the essay unpublished.')]
#[Mutates(Mutation::Persistent, rollback: 'essay:reopen')]
final readonly class Abandon
{
    public function __construct(public string $title, public string $feedback = '')
    {
    }

    public function run(): Receipt
    {
        return new Receipt('abandoned:' . $this->title);
    }
}
