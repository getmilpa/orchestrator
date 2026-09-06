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
 * This channel ACCUMULATES what another channel writes, instead of being overwritten.
 *
 * Reduction kinds are NAMED, not closures, and that is deliberate: a closure cannot be derived from,
 * refused at compile time, or replayed from a log. Last-write is the default and needs no attribute.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Appends
{
    /** @param string $of the channel whose every write is appended to this one */
    public function __construct(public string $of)
    {
    }
}
