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
use Milpa\Command\Declaration\Reads;
use Milpa\Command\Declaration\Target;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\InvocationContext;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\GraphRuns;

/**
 * Starts a run and advances it until it finishes or needs somebody.
 *
 * It mutates because a run is a fact appended to a log, and that log is what everything else reads.
 * Its reversibility is honest: nothing here can un-append what a node already did in the world, so
 * the way back is to answer the run's own gate.
 *
 * The REQUESTER is who started the run, as the surface attributed it — never an input (greenhouse decisions/0528).
 * It is what every gate the run opens records, and what `graph:decide` refuses as an approver; a requester the
 * caller could write was an approval the caller could arrange. A caller the surface did not verify is recorded as
 * `unverified:<channel>`, which no verified actor can be, so any verified human may answer what it opened.
 *
 * `graph:run` ADMITS THE START, NOT THE NODES. Each node runs as whoever started the run and only if that caller
 * holds what the node itself declares; one that does not is not run, and the result says so with the run parked there.
 */
#[Operation(name: 'graph:start', description: 'Start a declared graph and advance it until it finishes or needs a human.')]
#[Mutates(
    Mutation::Persistent,
    Externality::Unknown,
    Reversibility::ManualRecovery,
    subject: Subject::Data,
)]
#[Needs(scopes: ['graph:run'])]
final readonly class StartGraph
{
    public function __construct(
        #[Target]
        #[Because('Which graph to run — the name its own #[Graph] carries')]
        public string $graph,
        #[Because('The starting values for the graph channels it requires, as a JSON object')]
        public string $inputs = '{}',
    ) {
    }

    /**
     * Starts the run and reports where it came to rest.
     *
     * This is what a declared operation exposes, and a declared `run()` is never handed the caller's authority. So a
     * run started through here has a caller that holds nothing: its nodes are handed the context, and those that
     * declare a need do not run. The door ({@see GraphOperations}) calls {@see self::runAs()} instead.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs, ?InvocationContext $context = null): array
    {
        return $this->runAs($runs, new Caller($context));
    }

    /**
     * The same start, driven by a caller whose authority came with the call.
     *
     * A graph is a driver: it originates one governed call per node, and admission to `graph:start` does not
     * authorize them. So it takes what a surface hands every handler as its third argument — the caller's authority —
     * and each node is judged against it.
     *
     * @return array<string, mixed>
     */
    public function runAs(GraphRuns $runs, Caller $caller): array
    {
        return $runs->start($this->graph, $this->decoded(), $caller->requester(), $caller);
    }

    /**
     * The starting channels, read from JSON.
     *
     * They arrive as TEXT and not as an array on purpose: a graph's channels differ per graph, so
     * this input is a bag — and a bag cannot cross a command-line flag, an MCP argument and an HTTP
     * field as anything but text. Measured on cattle: typed as `array`, `graph:start` could be
     * called from a test and not from a terminal, which makes «reachable from every surface» false.
     *
     * @return array<string, mixed>
     */
    private function decoded(): array
    {
        $decoded = json_decode($this->inputs, true);

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException(
                "graph:start: `inputs` must be a JSON object of starting channels, and this is not one: {$this->inputs}"
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
