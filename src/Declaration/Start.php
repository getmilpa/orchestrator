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

namespace Milpa\Orchestrator\Declaration;

/**
 * Where the graph begins. Exactly one, and it must be one of the graph's nodes.
 *
 * The engine has always required exactly one initial state; this is where a reader can see which.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Start
{
    /** @param class-string $node */
    public function __construct(public string $node)
    {
    }
}
