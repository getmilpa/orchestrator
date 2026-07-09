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

use Milpa\Orchestrator\DecisionSurfaceFactoryInterface;
use Milpa\Orchestrator\DecisionSurfaceInterface;
use Milpa\Orchestrator\ProcessInstance;

/**
 * Test-only {@see DecisionSurfaceFactoryInterface}: builds a {@see StubDecisionSurface} whose
 * options are exactly the given transitions' names — always satisfies {@see
 * \Milpa\Orchestrator\PendingDecision}'s 1:1 invariant by construction, since it has no domain
 * data of its own to get out of sync.
 */
final class StubDecisionSurfaceFactory implements DecisionSurfaceFactoryInterface
{
    public function build(ProcessInstance $instance, array $transitions, array $context): DecisionSurfaceInterface
    {
        return new StubDecisionSurface(array_column($transitions, 'name'));
    }
}
