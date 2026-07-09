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

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\Support\UuidGenerator;

/**
 * A process instance handle: an opaque `instanceId` bound to the {@see ProcessDefinition} that
 * governs it. Holds NO event data of its own — every state-reading method takes the {@see
 * EventStoreInterface} explicitly and replays through the {@see Reducer}, so two `ProcessInstance`
 * objects built from the same `instanceId` + `definition` against the same log always agree, and
 * neither one ever caches a stale answer ("state is a projection", not a stored field).
 *
 * The constructor is the "attach to an existing instance" path — cheap, side-effect-free,
 * usable any time a caller already knows an `instanceId` (e.g. a tool call carrying one).
 * {@see self::start()} is the ONLY path that creates a brand-new instance (it is the one that
 * writes to the store); calling it twice with the same `instanceId` would duplicate the
 * bootstrap events, so callers resuming an existing instance must use `new self(...)` instead.
 */
final readonly class ProcessInstance
{
    use UuidGenerator;

    public function __construct(
        public string $instanceId,
        public ProcessDefinition $definition,
    ) {
    }

    /**
     * Starts a brand-new process instance: appends `ProcessStarted` (payload = `$inputs`), then
     * `StateEntered` (payload = `{state: $definition->initialState()}`), to `$store`, and
     * returns the resulting handle. `$instanceId` defaults to a freshly generated UUID (via
     * {@see UuidGenerator}) when omitted — pass one explicitly for deterministic tests or
     * caller-assigned ids.
     *
     * @param array<string, mixed> $inputs the new instance's starting context (e.g. domain ids)
     */
    public static function start(
        EventStoreInterface $store,
        ProcessDefinition $definition,
        array $inputs,
        ?string $instanceId = null,
    ): self {
        $instanceId ??= self::generateUuid();

        $store->append(new Event($instanceId, 'ProcessStarted', $inputs, $store->nextSeq()));
        $store->append(new Event($instanceId, 'StateEntered', ['state' => $definition->initialState()], $store->nextSeq()));

        return new self($instanceId, $definition);
    }

    /**
     * The full projection — current state and accumulated context — replayed fresh from
     * `$store` on every call.
     */
    public function state(EventStoreInterface $store): ProcessState
    {
        return (new Reducer())->apply($store->replay($this->instanceId), $this->definition);
    }

    /**
     * Convenience accessor for {@see self::state()}'s `currentState`.
     */
    public function currentState(EventStoreInterface $store): string
    {
        return $this->state($store)->currentState;
    }

    /**
     * Convenience accessor for {@see self::state()}'s `context`.
     *
     * @return array<string, mixed>
     */
    public function context(EventStoreInterface $store): array
    {
        return $this->state($store)->context;
    }
}
