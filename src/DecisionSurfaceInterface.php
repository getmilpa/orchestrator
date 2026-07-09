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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;

/**
 * The human decision surface for a gated process state: a `milpa/live` component ({@see
 * ComponentDefinitionInterface}) whose {@see self::options()} MUST equal — 1:1, order-insensitive
 * — the names of the transitions the gate it renders offers. {@see PendingDecision}'s constructor
 * enforces that invariant for every `DecisionSurfaceInterface` this package builds a {@see
 * PendingDecision} around, so a mismatch (a stale artifact after a process definition renamed,
 * added, or removed a gated transition) fails loudly at construction rather than silently
 * offering stale actions.
 *
 * This package ships only the contract and that invariant — the concrete rendering (what a
 * gate's decision surface actually LOOKS like: a blog post under review, an invoice awaiting
 * sign-off, ...) is domain, supplied by a consumer's {@see DecisionSurfaceFactoryInterface}
 * implementation.
 */
interface DecisionSurfaceInterface extends ComponentDefinitionInterface
{
    /**
     * The transition names this surface offers as decisions — MUST equal the gate's transition
     * names 1:1, order-insensitive (enforced by {@see PendingDecision}'s constructor).
     *
     * @return list<string>
     */
    public function options(): array;
}
