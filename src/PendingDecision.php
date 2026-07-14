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

namespace Milpa\Orchestrator;

/**
 * A gate that {@see HumanGate::openFor()} has opened (or {@see HumanGate::pendingFor()} has
 * found already open) and is now awaiting a human decision on. `$options` is the gate's
 * transition names; the constructor enforces they equal `$artifact->options()` 1:1
 * (order-insensitive) — the artifact<->gate invariant every {@see DecisionSurfaceInterface} this
 * package builds a `PendingDecision` around must satisfy, so a stale or mismatched artifact fails
 * loudly at construction rather than silently offering options the gate does not actually have.
 */
final readonly class PendingDecision
{
    /**
     * @param string                   $instanceId the process instance this decision belongs to
     * @param string                   $gateId     the opened gate's code (e.g. `review_gate_gate`)
     * @param string                   $assignee   the role expected to resolve this gate (the gate's approver role)
     * @param DecisionSurfaceInterface $artifact   the decision surface built for this gate
     * @param list<string>             $options    the transition names available to resolve this gate with
     *
     * @throws \InvalidArgumentException when `$artifact->options()` does not equal `$options` 1:1
     *                                   (order-insensitive) — the artifact<->gate invariant
     */
    public function __construct(
        public string $instanceId,
        public string $gateId,
        public string $assignee,
        public DecisionSurfaceInterface $artifact,
        public array $options,
    ) {
        $expected = $this->options;
        sort($expected);

        $actual = $this->artifact->options();
        sort($actual);

        if ($expected !== $actual) {
            throw new \InvalidArgumentException(sprintf(
                "PendingDecision: artifact options [%s] do not match the gate's transition options [%s].",
                implode(', ', $actual),
                implode(', ', $expected),
            ));
        }
    }
}
