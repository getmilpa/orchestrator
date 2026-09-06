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

namespace Milpa\Orchestrator\Operations;

use Milpa\Command\Declaration\Because;
use Milpa\Command\Declaration\Mutates;
use Milpa\Command\Declaration\Needs;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Target;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Orchestrator\Declaration\GraphRuns;

/**
 * Answers one waiting decision, and lets the run continue from there.
 *
 * The principal is not decoration: this engine refuses a decision submitted by the same principal
 * that opened the gate, so the agent that produced the work cannot be the one that approves it.
 */
#[Operation(name: 'graph:decide', description: 'Answer a decision a run is waiting on, and let it continue.')]
#[Mutates(
    Mutation::Persistent,
    Externality::Unknown,
    Reversibility::ManualRecovery,
    subject: Subject::Data,
)]
#[Needs(scopes: ['graph:decide'])]
final readonly class DecideGraph
{
    public function __construct(
        #[Because('Which graph the run belongs to')]
        public string $graph,
        #[Target]
        #[Because('Which run is being answered')]
        public string $instance,
        #[Because('The answer — it must be one of the options the gate offered')]
        public string $decision,
        #[Because('Who is answering; it must differ from whoever the gate recorded as requester')]
        public string $principal,
    ) {
    }

    /**
     * Records the answer and advances the run from there.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs): array
    {
        return $runs->decide($this->graph, $this->instance, $this->decision, $this->principal);
    }
}
