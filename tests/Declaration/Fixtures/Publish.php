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
use Milpa\Command\Declaration\Needs;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;

#[Operation(name: 'essay:publish', description: 'Publish the accepted essay.')]
#[Mutates(
    Mutation::Persistent,
    Externality::Public,
    Reversibility::Compensatable,
    subject: Subject::Data,
    rollback: 'essay:unpublish',
)]
#[Needs(scopes: ['essay:publish'])]
final readonly class Publish
{
    public function __construct(public string $title, public string $draft)
    {
    }

    public function run(): Receipt
    {
        return new Receipt('https://example.test/' . rawurlencode($this->title));
    }
}
