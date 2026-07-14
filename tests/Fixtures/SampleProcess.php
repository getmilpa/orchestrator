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

namespace Milpa\Orchestrator\Tests\Fixtures;

use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;

/**
 * A generic, domain-free sample process used by this package's own test suite: a 3-state
 * `draft --submit--> review_gate --approve--> done` machine, with `review_gate --reject--> draft`
 * sending a rejected draft back for revision. `review_gate` is a human checkpoint: both of its
 * outgoing transitions (`approve`, `reject`) carry the SAME {@see GateDefinition} instance under
 * a single-approval policy. Deliberately NOT the blog's `PublishPostProcess` (or any other domain
 * process) — this engine composes any `milpa/workflow` state/transition/gate shape, and this
 * fixture proves that against a shape that owns no domain concept at all.
 */
final class SampleProcess
{
    public const string NAME = 'sample_process';

    public const string STATE_DRAFT = 'draft';
    public const string STATE_REVIEW_GATE = 'review_gate';
    public const string STATE_DONE = 'done';

    public const string TRANSITION_SUBMIT = 'submit';
    public const string TRANSITION_APPROVE = 'approve';
    public const string TRANSITION_REJECT = 'reject';

    /**
     * Builds a fresh {@see ProcessDefinition}: 3 states, 3 transitions, 1 human gate on
     * `review_gate`. A fresh definition is built on every call — `milpa/workflow`'s entities are
     * plain objects here (never persisted), so there is no shared mutable state to guard between
     * callers.
     */
    public static function build(): ProcessDefinition
    {
        $draft = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_DRAFT)
            ->setLabel('Draft')
            ->setSortOrder(0)
            ->setIsInitial(true);

        $reviewGate = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_REVIEW_GATE)
            ->setLabel('Review Gate')
            ->setSortOrder(1);

        $done = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_DONE)
            ->setLabel('Done')
            ->setSortOrder(2)
            ->setIsTerminal(true);

        $gate = (new GateDefinition())
            ->setDomain(self::NAME)
            ->setCode('review_gate_gate')
            ->setName('Sample review')
            ->setDescription('A human reviewer approves or rejects the draft; the requester cannot approve their own submission.')
            ->setRequesterRole('author')
            ->setApproverRole('reviewer')
            ->setApprovalPolicy(ApprovalPolicy::SINGLE);

        $submit = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_SUBMIT)
            ->setLabel('Submit for review')
            ->setFromState($draft)
            ->setToState($reviewGate);

        $approve = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_APPROVE)
            ->setLabel('Approve')
            ->setFromState($reviewGate)
            ->setToState($done);
        $approve->addGateDefinition($gate);

        $reject = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_REJECT)
            ->setLabel('Reject')
            ->setFromState($reviewGate)
            ->setToState($draft);
        $reject->addGateDefinition($gate);

        return new ProcessDefinition(
            [$draft, $reviewGate, $done],
            [$submit, $approve, $reject],
        );
    }
}
