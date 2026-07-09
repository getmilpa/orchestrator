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

namespace Milpa\Orchestrator\Tools;

use Milpa\EventStore\EventStoreInterface;

/**
 * Shared by {@see ProcessListPendingApprovalsTool} and {@see ProcessSubmitDecisionTool}: both
 * need to reconstruct a {@see \Milpa\Orchestrator\ProcessInstance} for a stream id they did NOT
 * just create, which needs a {@see \Milpa\Orchestrator\ProcessDefinition} — this engine's core
 * classes ({@see \Milpa\Orchestrator\ProcessInstance}, {@see \Milpa\Orchestrator\Reducer}, ...)
 * are generic and know nothing about a registry or a "definition name", so that bookkeeping is
 * confined to this tool-layer convention instead: {@see ProcessInstantiateTool} stamps the
 * registry name it resolved into the `ProcessStarted` bootstrap event's payload under
 * `_definition` (mirroring how it also stamps `_requester`), and this trait reads it back.
 */
trait ResolvesDefinitionNameTrait
{
    /**
     * The `_definition` value {@see ProcessInstantiateTool} stamped into `$streamId`'s
     * `ProcessStarted` event, or `null` when the stream has no such bootstrap event (unknown
     * stream, or one never started through this engine's tools).
     */
    private function definitionNameFor(EventStoreInterface $store, string $streamId): ?string
    {
        $events = $store->replay($streamId);
        $first = $events[0] ?? null;
        if ($first === null || $first->type !== 'ProcessStarted') {
            return null;
        }

        $name = $first->payload['_definition'] ?? null;

        return is_string($name) ? $name : null;
    }
}
