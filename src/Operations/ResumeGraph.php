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
use Milpa\Command\InvocationContext;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\GraphRuns;

/**
 * Carries on a run that parked because its caller could not run the next node.
 *
 * A node that declares a need does not run for a caller who lacks it, and the run parks AT that node: no gate is
 * waiting, so there is nothing to answer, and until this operation nothing moved it. Resuming is one more invocation
 * that reaches the node — so THE NODE RUNS AS WHOEVER RESUMES, and only if this caller holds what it declares. A
 * caller who may not run it either is refused the same way, and the run stays where it was.
 *
 * It asks for `graph:run`, like the start: it runs nodes and DECIDES NOTHING. It takes which run and nothing else —
 * no answer, no inputs — and it refuses a run that is waiting for a decision, which is `graph:decide`'s to answer.
 * Whoever started the run is still the one its gates record, so resuming one's own run is no way to approve it
 * (greenhouse decisions/0582).
 */
#[Operation(name: 'graph:resume', description: 'Carry on a run that parked because its caller could not run the next node.')]
#[Mutates(
    Mutation::Persistent,
    Externality::Unknown,
    Reversibility::ManualRecovery,
    subject: Subject::Data,
)]
#[Needs(scopes: ['graph:run'])]
final readonly class ResumeGraph
{
    public function __construct(
        #[Because('Which graph the run belongs to')]
        public string $graph,
        #[Target]
        #[Because('Which run to carry on')]
        public string $instance,
    ) {
    }

    /**
     * Resumes the run and reports where it came to rest.
     *
     * This is what a declared operation exposes, and a declared `run()` is never handed the caller's authority. So
     * through here the caller holds nothing, and the node the run is parked at — which declares a need, or the run
     * would not be parked — does not run. The door ({@see GraphOperations}) calls {@see self::runAs()} instead.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs, ?InvocationContext $context = null): array
    {
        return $this->runAs($runs, new Caller($context));
    }

    /**
     * Resumes the run as `$caller`: who is asking, and what the surface verified they hold.
     *
     * @return array<string, mixed>
     */
    public function runAs(GraphRuns $runs, Caller $caller): array
    {
        return $runs->resume($this->graph, $this->instance, $caller);
    }
}
