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

use Milpa\Workflow\Enums\ApprovalPolicy;

/**
 * A human decides here — the gate, declared where the graph is read.
 *
 * The options are the cases of `$options`, which is the SAME source the {@see Route}s leaving this
 * gate come from, so the surface and the transitions cannot drift apart. What this compiles to is
 * the gate the engine already has, which means the whole posture comes along: the decision is an
 * event in an append-only log, the run parks instead of blocking, and the principal that OPENED the
 * gate cannot resolve it — so the agent that produced the work structurally cannot approve it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Ask
{
    /**
     * @param class-string $options  The enum whose cases are the offered decisions.
     * @param string       $of       The role that may answer.
     * @param string       $because  Why a human is being asked — the text the decision surface shows.
     * @param list<string> $evidence Evidence types the answer must carry.
     * @param bool         $waivable Whether this gate may be skipped at all.
     */
    public function __construct(
        public string $options,
        public string $of,
        public string $because,
        public ApprovalPolicy $policy = ApprovalPolicy::SINGLE,
        public array $evidence = [],
        public bool $waivable = true,
    ) {
    }
}
