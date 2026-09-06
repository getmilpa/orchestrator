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

use Milpa\Command\Operation;
use Milpa\Orchestrator\ProcessDefinition;

/**
 * What a declared graph compiles DOWN to — and every part of it is something the engine already ate.
 *
 * There is no new runtime here. The `definition` is the same `ProcessDefinition` a hand-written
 * process builds, the operations are the same `Operation` value objects every projector reads, and
 * the routing tables are what the node invoker consults to turn a returned enum case into an edge.
 */
final readonly class CompiledGraph
{
    /**
     * @param array<string, Operation>                                             $operations state code → the node's operation
     * @param array<string, array{enum: class-string, map: array<string, string>}> $routing    state code → its routing enum and case→transition map
     * @param array<string, mixed>                                                 $channels   channel name → its declared default
     * @param array<string, string>                                                $appends    channel → the channel whose writes it accumulates
     * @param array<string, int>                                                   $budgets    transition code → how many times it may be taken
     * @param array<string, string>                                                $exhausted  transition code → the transition taken once its budget is spent
     */
    public function __construct(
        public string $name,
        public string $description,
        public ProcessDefinition $definition,
        public array $operations,
        public array $routing,
        public array $channels,
        public array $appends,
        public array $budgets,
        public array $exhausted,
    ) {
    }
}
