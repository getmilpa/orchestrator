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
 * A THIRD level wrapping {@see SubprocessParentProcess} (which itself wraps {@see SampleProcess})
 * as its own subprocess child: `kickoff --start--> delegate (subprocess: {@see
 * SubprocessParentProcess}) --[named after the parent's outcome, 'finished']--> closed`. Exists
 * solely to prove `subprocess_done` routing recurses UP more than one level — a grandchild's
 * (`SampleProcess`) terminal state must route to its parent (`SubprocessParentProcess`), whose OWN
 * resulting terminal state must in turn route to ITS parent (this class).
 */
final class SubprocessGrandparentProcess
{
    public const string NAME = 'subprocess_grandparent_process';

    public const string STATE_KICKOFF = 'kickoff';
    public const string STATE_DELEGATE = 'delegate';
    public const string STATE_CLOSED = 'closed';

    public const string TRANSITION_START = 'start';

    /**
     * Builds a fresh {@see ProcessDefinition}: 3 states, 2 transitions, 1 subprocess state
     * (`delegate`, delegating to {@see SubprocessParentProcess}).
     */
    public static function build(): ProcessDefinition
    {
        $kickoff = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_KICKOFF)
            ->setLabel('Kickoff')
            ->setSortOrder(0)
            ->setIsInitial(true);

        $delegate = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_DELEGATE)
            ->setLabel('Delegate (subprocess)')
            ->setSortOrder(1);

        $closed = (new StateDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::STATE_CLOSED)
            ->setLabel('Closed')
            ->setSortOrder(2)
            ->setIsTerminal(true);

        $start = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(self::TRANSITION_START)
            ->setLabel('Start delegation')
            ->setFromState($kickoff)
            ->setToState($delegate);

        // Named after SubprocessParentProcess's terminal state code ('finished').
        $subprocessDone = (new TransitionDefinition())
            ->setDomain(self::NAME)
            ->setCode(SubprocessParentProcess::STATE_FINISHED)
            ->setLabel('Subprocess done')
            ->setFromState($delegate)
            ->setToState($closed);

        return new ProcessDefinition(
            [$kickoff, $delegate, $closed],
            [$start, $subprocessDone],
            [
                self::STATE_DELEGATE => new SubprocessSpec(
                    definitionRef: SubprocessParentProcess::NAME,
                    inputsMap: ['ref' => 'ref'],
                ),
            ],
        );
    }
}
