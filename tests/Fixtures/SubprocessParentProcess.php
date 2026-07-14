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
use Milpa\Orchestrator\SubprocessSpec;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;

/**
 * A generic, domain-free PARENT process whose middle state is a SUBPROCESS state: `kickoff
 * --start--> review (subprocess: {@see SampleProcess}) --[named after the child's outcome,
 * 'done']--> finished`. Deliberately reuses {@see SampleProcess} — the SAME fixture rebanada 1's
 * own suite already proves runs a human gate to terminal — as the CHILD, exactly mirroring how the
 * spec's own `publish_campaign` demo reuses `publish_post` unmodified as a subprocess.
 *
 * `review`'s {@see SubprocessSpec} projects the parent's own `ref` context key into the child's
 * `ref` input (`inputsMap`), and declares `ref` itself as a DECLARED OUTPUT to copy back once the
 * child finishes (`outputs`) — proving both directions of the parent<->child context bridge.
 */
final class SubprocessParentProcess
{
    public const string NAME = 'subprocess_parent_process';

    public const string STATE_KICKOFF = 'kickoff';
    public const string STATE_REVIEW = 'review';
    public const string STATE_FINISHED = 'finished';

    public const string TRANSITION_START = 'start';

    /**
     * Builds a fresh {@see ProcessDefinition}: 3 states, 2 transitions, 1 subprocess state
     * (`review`, delegating to {@see SampleProcess}). A fresh definition is built on every call —
     * see {@see SampleProcess::build()}'s own docblock for why.
     */
    public static function build(): ProcessDefinition
    {
        $kickoff = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_KICKOFF)
            ->setLabel('Kickoff')
            ->setSortOrder(0)
            ->setIsInitial(true);

        $review = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_REVIEW)
            ->setLabel('Review (subprocess)')
            ->setSortOrder(1);

        $finished = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_FINISHED)
            ->setLabel('Finished')
            ->setSortOrder(2)
            ->setIsTerminal(true);

        $start = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_START)
            ->setLabel('Start review')
            ->setFromState($kickoff)
            ->setToState($review);

        // Named after SampleProcess's terminal state code ('done') — its only possible outcome —
        // NOT the literal string 'subprocess_done'. See ProcessRunner's own docblock for why.
        $subprocessDone = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(SampleProcess::STATE_DONE)
            ->setLabel('Subprocess done (approved)')
            ->setFromState($review)
            ->setToState($finished);

        return new ProcessDefinition(
            [$kickoff, $review, $finished],
            [$start, $subprocessDone],
            [
                self::STATE_REVIEW => new SubprocessSpec(
                    definitionRef: SampleProcess::NAME,
                    inputsMap: ['ref' => 'ref'],
                    outputs: ['ref'],
                ),
            ],
        );
    }
}
