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

use Milpa\Orchestrator\Declaration\Ask;
use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Start;

/** #[Ask] naming something that is not an enum. */
#[Graph(name: 'lab:not-enum', description: 'Asks with a class that has no cases.')]
#[Start(Plain::class)]
#[Ask(Plain::class, of: 'editor', because: 'x')]
final readonly class NotAnEnum
{
    public function __construct(public string $title)
    {
    }
}
