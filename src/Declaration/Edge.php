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
 * An unconditional edge: when this node returns, the graph goes there.
 *
 * A node with one outgoing edge does not decide anything, so nothing needs to be routed. A node with
 * MORE than one outgoing path decides, and then the paths are {@see Route}s named by the cases of
 * the enum that node returns.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Edge
{
    /**
     * @param class-string $from
     * @param class-string $to
     */
    public function __construct(
        public string $from,
        public string $to,
    ) {
    }
}
