<?php

/**
 * This file is part of milpa/orchestrator — the generic event-sourced process engine of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
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
 * Builds one "level" of an arbitrarily long, PURELY LINEAR chain of subprocess-referencing
 * definitions — used to exercise {@see \Milpa\Orchestrator\ProcessDefinitionRegistry}'s cycle
 * detection (a level referencing itself, or two levels referencing each other) and {@see
 * \Milpa\Orchestrator\ProcessRunner}'s runtime depth limit (a long, deliberately NON-cyclic chain
 * that simply nests deeper than the limit allows).
 *
 * Every non-bottom level has the exact same shape: state `begin` (initial, subprocess state
 * referencing the next level by name) --`done`--> state `done` (terminal). Because EVERY level's
 * terminal state is coded `done`, and a subprocess state's outgoing transition must be named after
 * the CHILD's outcome (its terminal state's code — see {@see
 * \Milpa\Orchestrator\ProcessRunner}'s docblock), the single transition `begin --done--> done` is
 * correct for every level in the chain, uniformly, with no per-level naming to keep straight.
 * {@see self::bottom()} builds the chain's tail: a single state that is BOTH initial and terminal,
 * with no subprocess and no transitions — the chain's actual base case.
 */
final class ChainLevelProcess
{
    public const string STATE_BEGIN = 'begin';
    public const string STATE_DONE = 'done';

    /**
     * Builds a level that delegates to `$childDefinitionRef` before reaching its own terminal
     * state.
     */
    public static function nonBottom(string $domain, string $childDefinitionRef): ProcessDefinition
    {
        $begin = (new StateDefinition())
            ->setDomain($domain)
            ->setCode(self::STATE_BEGIN)
            ->setLabel('Begin')
            ->setSortOrder(0)
            ->setIsInitial(true);

        $done = (new StateDefinition())
            ->setDomain($domain)
            ->setCode(self::STATE_DONE)
            ->setLabel('Done')
            ->setSortOrder(1)
            ->setIsTerminal(true);

        $transition = (new TransitionDefinition())
            ->setDomain($domain)
            ->setCode(self::STATE_DONE)
            ->setLabel('Subprocess done')
            ->setFromState($begin)
            ->setToState($done);

        return new ProcessDefinition(
            [$begin, $done],
            [$transition],
            [self::STATE_BEGIN => new SubprocessSpec(definitionRef: $childDefinitionRef)],
        );
    }

    /**
     * Builds the chain's base case: a single state, both initial and terminal, with no
     * subprocess — reaches terminal immediately with no external trigger needed.
     */
    public static function bottom(string $domain): ProcessDefinition
    {
        $done = (new StateDefinition())
            ->setDomain($domain)
            ->setCode(self::STATE_DONE)
            ->setLabel('Done')
            ->setSortOrder(0)
            ->setIsInitial(true)
            ->setIsTerminal(true);

        return new ProcessDefinition([$done], []);
    }
}
