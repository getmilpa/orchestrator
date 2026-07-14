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

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\Workflow\Contracts\GateServiceInterface;
use Milpa\Workflow\Services\InMemoryGateService;

/**
 * Opens and resolves a process instance's gate — the event-sourced counterpart to
 * `milpa/workflow`'s Doctrine-backed `GatePassageService`.
 *
 * This engine is zero-DB (an append-only {@see EventStoreInterface} log), so instead of
 * `GatePassageService` (which requires a real `EntityManagerInterface` and `persist()`/`flush()`s
 * every passage), `HumanGate` represents "the gate is open, awaiting a decision" as a
 * `GateOpened` {@see Event} in the SAME log the process instance itself replays through. It
 * still delegates the D9 anti-self-approval check to `milpa/workflow`'s {@see GateServiceInterface}
 * — by default {@see InMemoryGateService}, the zero-DB implementation built for exactly this
 * seam — rather than reimplementing that check inline: {@see self::resolve()} reconstructs a
 * throwaway `GatePassage` carrying the ORIGINAL requester (read back from the log's `GateOpened`
 * event) and calls `$gateService->approvePassage()` purely to obtain its self-approval guard;
 * the passage's own approval-count/status bookkeeping is discarded, since the actual process
 * advance is the literal decision event this class appends to `$store` regardless of what
 * `approvePassage()` did to the throwaway passage.
 *
 * `$gateService->requestPassage()`/`approvePassage()` need a polymorphic `entityType`/`entityId`
 * pair, but this engine has no such concept (a process instance's id is an opaque string, not an
 * int-keyed entity) and the self-approval check does not depend on either value — so both are
 * fixed, meaningless placeholders here (see {@see self::ENTITY_TYPE}), never read back by any
 * caller of this class.
 */
final class HumanGate
{
    private const string ENTITY_TYPE = 'orchestrator_process_instance';

    public function __construct(
        private readonly DecisionSurfaceFactoryInterface $artifacts,
        private readonly GateServiceInterface $gateService = new InMemoryGateService(),
    ) {
    }

    /**
     * Opens `$instance`'s current gate for a human decision: appends a `GateOpened` event
     * (payload: `requester` + the gate's `options`), then builds this gate's {@see
     * DecisionSurfaceInterface} via the injected {@see DecisionSurfaceFactoryInterface} and
     * returns the resulting {@see PendingDecision}.
     *
     * @throws \RuntimeException         when `$instance`'s current state has no gate
     * @throws \InvalidArgumentException when the built artifact's options do not match the
     *                                   gate's transitions 1:1 (see {@see PendingDecision})
     */
    public function openFor(
        EventStoreInterface $store,
        ProcessInstance $instance,
        string $requester,
    ): PendingDecision {
        $state = $instance->currentState($store);
        $gate = $instance->definition->gateFor($state);
        if ($gate === null) {
            throw new \RuntimeException(sprintf(
                "HumanGate: instance '%s' is at state '%s', which has no gate to open.",
                $instance->instanceId,
                $state,
            ));
        }

        $transitions = $instance->definition->transitionsFrom($state);
        $options = array_column($transitions, 'name');
        $gateId = $gate->getCode();

        $store->append(new Event($instance->instanceId, 'GateOpened', [
            'gate_id' => $gateId,
            'requester' => $requester,
            'options' => $options,
        ], $store->nextSeq()));

        $artifact = $this->artifacts->build($instance, $transitions, $instance->context($store));

        return new PendingDecision(
            instanceId: $instance->instanceId,
            gateId: $gateId,
            assignee: $gate->getApproverRole(),
            artifact: $artifact,
            options: $options,
        );
    }

    /**
     * Resolves `$gateId` for `$instance` with `$decision`: validates `$decision` is one of the
     * options the matching `GateOpened` event offered, delegates the D9 anti-self-approval check
     * to the injected {@see GateServiceInterface} (see the class docblock), then appends
     * `$decision` itself as the advancing {@see Event} — the SAME mechanism any trigger event
     * uses to move the process forward (see {@see Reducer}).
     *
     * @throws \RuntimeException                                when `$instance` is not currently awaiting `$gateId`
     *                                                          (never opened, or already resolved — its current
     *                                                          state no longer carries a gate whose code matches
     *                                                          `$gateId`)
     * @throws \InvalidArgumentException                        when `$decision` is not one of the options the gate
     *                                                          was opened with
     * @throws \Milpa\Workflow\Exceptions\SelfApprovalException when `$principal` equals the
     *                                                          requester that opened `$gateId` (D9)
     */
    public function resolve(
        EventStoreInterface $store,
        ProcessInstance $instance,
        string $gateId,
        string $decision,
        string $principal,
    ): Event {
        $state = $instance->currentState($store);
        $gate = $instance->definition->gateFor($state);
        if ($gate === null || $gate->getCode() !== $gateId) {
            throw new \RuntimeException(sprintf(
                "HumanGate: instance '%s' is not currently awaiting gate '%s'.",
                $instance->instanceId,
                $gateId,
            ));
        }

        $opened = $this->latestGateOpened($store, $instance->instanceId, $gateId);
        if ($opened === null) {
            throw new \RuntimeException(sprintf(
                "HumanGate: gate '%s' was never opened for instance '%s'.",
                $gateId,
                $instance->instanceId,
            ));
        }

        /** @var list<string> $options */
        $options = $opened->payload['options'] ?? [];
        if (!in_array($decision, $options, true)) {
            throw new \InvalidArgumentException(sprintf(
                "HumanGate: '%s' is not a valid decision for gate '%s' (expected one of: %s).",
                $decision,
                $gateId,
                implode(', ', $options),
            ));
        }

        $requester = (string) ($opened->payload['requester'] ?? '');

        // Discarded after the self-approval check: this engine's authority for "what actually
        // happened" is the event $store, not a GatePassage's status field — see the class docblock.
        $passage = $this->gateService->requestPassage($gate, self::ENTITY_TYPE, 0, $requester);
        $this->gateService->approvePassage($passage, $principal);

        $event = new Event($instance->instanceId, $decision, ['by' => $principal], $store->nextSeq());
        $store->append($event);

        return $event;
    }

    /**
     * Read-only reconstruction of "what's the current pending decision on `$instance`, if any" —
     * WITHOUT appending a new `GateOpened` event. Finds the latest `GateOpened` event on
     * `$instance`'s own log and checks whether it is still UNRESOLVED (no later event, by `seq`,
     * whose `type` is one of that `GateOpened`'s own `options`), then rebuilds the {@see
     * PendingDecision} around it exactly like {@see self::openFor()} would have, minus the write.
     *
     * A caller wanting to list every pending decision (e.g. across every stream an {@see
     * EventStoreInterface} knows about) must use this instead of {@see self::openFor()}, which
     * would append a redundant `GateOpened` event on every call. {@see ProcessRunner} also uses
     * it to decide whether a gate still needs opening before it calls {@see self::openFor()}.
     *
     * Returns `null` when: no `GateOpened` event exists for `$instance` at all; the latest one
     * has already been resolved; `$instance`'s current state no longer carries a gate matching
     * the latest `GateOpened`'s `gate_id`; or the injected {@see DecisionSurfaceFactoryInterface}
     * throws while building the artifact (e.g. its domain lookup fails) — this method is
     * read-only and reports "nothing pending" rather than propagating that failure.
     */
    public function pendingFor(EventStoreInterface $store, ProcessInstance $instance): ?PendingDecision
    {
        $events = $store->replay($instance->instanceId);
        $opens = array_values(array_filter(
            $events,
            static fn (Event $event): bool => $event->type === 'GateOpened',
        ));
        if ($opens === []) {
            return null;
        }

        $latest = $opens[array_key_last($opens)];
        /** @var list<string> $options */
        $options = $latest->payload['options'] ?? [];

        foreach ($events as $event) {
            if ($event->seq > $latest->seq && in_array($event->type, $options, true)) {
                // A later event already resolved this GateOpened — nothing pending.
                return null;
            }
        }

        $gateId = (string) ($latest->payload['gate_id'] ?? '');
        $state = $instance->currentState($store);
        $gate = $instance->definition->gateFor($state);
        if ($gate === null || $gate->getCode() !== $gateId) {
            return null;
        }

        $transitions = $instance->definition->transitionsFrom($state);

        try {
            $artifact = $this->artifacts->build($instance, $transitions, $instance->context($store));

            return new PendingDecision(
                instanceId: $instance->instanceId,
                gateId: $gateId,
                assignee: $gate->getApproverRole(),
                artifact: $artifact,
                options: array_column($transitions, 'name'),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The most recent `GateOpened` event for `$gateId` on `$instanceId`, or `null` if that gate
     * was never opened. "Most recent" matters because a revise-and-resubmit loop can open the
     * same gate more than once across an instance's lifetime — only the latest opening's
     * `requester`/`options` are live.
     */
    private function latestGateOpened(EventStoreInterface $store, string $instanceId, string $gateId): ?Event
    {
        $matches = array_values(array_filter(
            $store->replay($instanceId),
            static fn (Event $event): bool => $event->type === 'GateOpened'
                && ($event->payload['gate_id'] ?? null) === $gateId,
        ));

        return $matches === [] ? null : $matches[array_key_last($matches)];
    }
}
