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
use Milpa\Workflow\Exceptions\SelfApprovalException;

/**
 * Answers one waiting decision, and lets the run continue from there.
 *
 * WHO ANSWERS IS WHO THE SURFACE AUTHENTICATED, never a name the caller writes (greenhouse decisions/0528). This
 * engine refuses a decision from the principal that opened the gate, so the agent that produced the work cannot be
 * the one that approves it — and that only holds if the approver is not an argument. An earlier version took a
 * `principal` input and judged the rule against it: an agent over MCP, an actor over HTTP and a signed terminal
 * each approved a gate they had opened by naming somebody else (evidence/1062). Now the approver is the verified
 * actor of the invocation — the passkey session behind the panel's door, the key behind a terminal's signature —
 * and a caller the surface did not verify (MCP stdio, an unsigned terminal) cannot answer a gate at all.
 *
 * WHAT RUNS AFTER THE ANSWER RUNS AS WHOEVER ANSWERED. `graph:decide` admits the answer, not the nodes it leads to:
 * those are judged against what the approver holds, never against what the starter held when the run parked. An
 * answer that leads straight to a node the approver may not run is not recorded, and the gate keeps waiting.
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
    ) {
    }

    /**
     * Records the answer on behalf of the verified caller and advances the run from there.
     *
     * A refusal is a result, not an exception: the person who pressed the button reads the sentence, and an HTTP
     * surface would hide an exception's message behind a 500.
     *
     * @return array<string, mixed>
     */
    public function run(GraphRuns $runs, ?InvocationContext $context = null): array
    {
        return $this->runAs($runs, new Caller($context));
    }

    /**
     * The same answer, given by a caller whose authority came with the call — what the door ({@see GraphOperations})
     * calls. Through {@see self::run()} the approver holds nothing, so only answers leading to nodes that declare no
     * need are taken.
     *
     * @return array<string, mixed>
     */
    public function runAs(GraphRuns $runs, Caller $caller): array
    {
        $context = $caller->context;

        if ($context === null || !$context->isAttributable()) {
            return [
                'ok' => false,
                'error' => 'A gate is answered by a verified actor — a passkey session or a signed call — and this call carries none. '
                    . 'The approver is read from who the surface authenticated, never from the arguments.',
            ];
        }

        try {
            return $runs->decide($this->graph, $this->instance, $this->decision, (string) $context->actor, $caller);
        } catch (SelfApprovalException) {
            return [
                'ok' => false,
                'error' => "{$context->actor} opened this gate, so it cannot approve it: the work and its approval need two different people.",
            ];
        }
    }
}
