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

namespace Milpa\Orchestrator\Declaration;

use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * Who is driving a run right now: the invocation that started it, or the one that answered its gate.
 *
 * It is the two things a surface already hands every handler, kept as the two things they are: the CONTEXT says who
 * to attribute the call to, and the AUTHORITY says what that caller may do. A node is handed both, exactly as a
 * surface would hand them to it — and before it runs, what it declares it needs is judged against the authority.
 *
 * ── NOTHING HERE IS REMEMBERED ──────────────────────────────────────────────────────────────────────────────────
 *
 * A caller lives for one invocation. It is never written to the log as authority and never read back from it: a run
 * that parks at a gate does not keep the scopes of whoever started it. So the nodes before a gate run as whoever
 * started the run, and the nodes after it run as whoever answered — each judged against what THAT caller holds at the
 * moment the node runs. A stored authority would be one that anybody able to answer the gate could spend, long after
 * it was revoked; and an answer that borrowed the starter's scopes would let an approver do what nobody verified they
 * may. What the log does keep is {@see self::record()}: who ran each node, so a reader can tell the two apart.
 *
 * ── AN ABSENT AUTHORITY HOLDS NOTHING ───────────────────────────────────────────────────────────────────────────
 *
 * That is the closed default. A node that declares a need does not run for a call nobody vouched for; a node that
 * declares none runs exactly as it always did.
 */
final readonly class Caller
{
    public function __construct(
        public ?InvocationContext $context = null,
        public ?ToolContext $authority = null,
    ) {
    }

    /**
     * Why this caller may not run `$node`, as a fragment that completes «the node did not run: …» — or `null` when
     * it may.
     *
     * Scopes are alternatives, as everywhere in the family: holding any one of them admits the call. A permission is
     * judged by a policy this engine does not have, so only the wildcard an owner holds passes it — a finite caller
     * is refused, because not knowing is not allowing. And an authority with no principal behind it holds nothing:
     * scopes nobody can be named for would run a node the log could only attribute to nobody.
     *
     * What the caller DOES hold is never part of the answer: it would end in the log and in a result, and neither
     * needs it.
     */
    public function refusalOf(Operation $node): ?string
    {
        if ($node->scopes === [] && $node->permission === null) {
            return null;
        }

        $needed = match (true) {
            $node->permission !== null => "the permission '{$node->permission}'",
            \count($node->scopes) === 1 => "the scope {$node->scopes[0]}",
            default => 'one of the scopes ' . implode(', ', $node->scopes),
        };

        if ($this->authority === null) {
            return "it needs {$needed}, and this call carries no authority to judge";
        }

        if ($this->authority->principal === null || $this->authority->principal === '') {
            return "it needs {$needed}, and this call names nobody its authority could be judged for";
        }

        $who = $this->context !== null && $this->context->actor !== null ? $this->context->actor : $this->authority->principal;

        if ($node->permission !== null) {
            return $this->authority->hasScope('*')
                ? null
                : "it needs {$needed}, and nothing here can judge a permission for {$who} — not knowing is not allowing";
        }

        if ($this->authority->hasAnyScope($node->scopes)) {
            return null;
        }

        return \count($node->scopes) === 1
            ? "it needs {$needed}, which {$who} does not hold"
            : "it needs {$needed}, and {$who} holds none of them";
    }

    /**
     * The name a run keeps for whoever asked for it: the actor the surface verified, or — when it verified nobody —
     * the door the call came through.
     *
     * A name is not taken on the caller's word. An actor nobody verified is whatever the call says it is, and this
     * is the name a gate later refuses to be approved by.
     */
    public function requester(): string
    {
        if ($this->context !== null && $this->context->isAttributable()) {
            return (string) $this->context->actor;
        }

        return 'unverified:' . ($this->context === null ? 'unknown' : $this->context->channel);
    }

    /**
     * What the log keeps about who ran a node: who, by which door, under which authorizing decision — never what
     * the caller holds.
     *
     * The ACTOR is who the surface attributed the call to; the PRINCIPAL is who its authority was judged for. They
     * are usually the same person written two ways (`actor:rod` and `rod`), and they are kept apart because they
     * answer different questions — and because on a surface that attributes nothing the actor is absent while the
     * principal is not.
     *
     * Two things an `InvocationContext` carries are left out on purpose. The EXECUTOR is the server's own account
     * and host name, and this record is read back by whoever may read a run. The CORRELATION id is, over HTTP, a
     * header the caller writes — free text has no place inside a record that says who did what.
     *
     * @return array<string, bool|string|null>
     */
    public function record(): array
    {
        $record = [
            'actor' => $this->context?->actor,
            'verified' => $this->context !== null && $this->context->verified,
            'channel' => $this->context !== null ? $this->context->channel : ($this->authority !== null ? $this->authority->channel : 'unknown'),
            'principal' => $this->authority?->principal,
        ];

        if ($this->context !== null && $this->context->authorizationId !== null) {
            $record['authorization'] = $this->context->authorizationId;
        }

        return $record;
    }
}
