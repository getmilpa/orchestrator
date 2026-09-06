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

/**
 * A conditional edge, named by the enum case that chooses it.
 *
 * This is the whole idea of this SDK in one attribute: **an edge is an edge whether a model or a
 * human chose it.** `Route(when: Verdict::Failed, …)` is what a grader's structured output picks;
 * `Route(when: EditorCall::OneMoreRound, …)` is what a person picks at a gate. Same syntax, and the
 * only difference is who produced the enum value and what authority that spent.
 *
 * The origin is DERIVED: exactly one node returns a `Verdict`, so `from:` is only written when two
 * nodes return the same enum and the answer is genuinely ambiguous.
 *
 * `atMost` is the loop budget, and it is what makes a cycle legal. The engine refuses a cycle of
 * ungated transitions because such a cycle has no escape; a declared budget IS an escape, and when
 * it runs out `thenAsk` hands the decision to a human instead of simply stopping — which is the
 * difference between a graph library and a governed one.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * @param \UnitEnum         $when    The case that takes this edge; its enum is the node's return contract.
     * @param class-string      $to      The node this edge leads to.
     * @param class-string|null $from    Only when two nodes return the same enum.
     * @param int|null          $atMost  How many times this edge may be taken before the budget is spent.
     * @param class-string|null $thenAsk The options enum offered to a human once the budget is spent.
     */
    public function __construct(
        public \UnitEnum $when,
        public string $to,
        public ?string $from = null,
        public ?int $atMost = null,
        public ?string $thenAsk = null,
    ) {
    }
}
