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

namespace Milpa\Orchestrator;

/**
 * Builds the domain-specific {@see DecisionSurfaceInterface} for a process instance sitting at a
 * gated state. Injected into {@see HumanGate} so the generic engine never needs to know what a
 * process's domain entity is (a blog post, an invoice, a support ticket, ...) — only the
 * consumer's factory implementation does. {@see HumanGate} calls {@see self::build()} with
 * exactly the gate's outgoing transitions and the instance's current context (e.g. domain ids
 * stamped at {@see ProcessInstance::start()} time), and wraps the result in a {@see
 * PendingDecision}, whose constructor enforces the options<->transitions 1:1 invariant.
 */
interface DecisionSurfaceFactoryInterface
{
    /**
     * Builds the decision surface for `$instance` sitting at a gated state.
     *
     * @param list<array{name: string, to: string}> $transitions the gate's outgoing transitions;
     *                                                           the returned surface's {@see
     *                                                           DecisionSurfaceInterface::options()}
     *                                                           MUST match these names 1:1
     * @param array<string, mixed>                  $context     the process instance's current
     *                                                           context (accumulated event payload)
     */
    public function build(ProcessInstance $instance, array $transitions, array $context): DecisionSurfaceInterface;
}
