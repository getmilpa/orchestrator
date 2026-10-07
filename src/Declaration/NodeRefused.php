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

use Milpa\Command\Operation;

/**
 * A node did not run, because whoever is driving the run does not hold what the node declares it needs.
 *
 * It is thrown by {@see NodeInvoker} BEFORE the node's handler is reached, so nothing of the node happened. It is an
 * exception only for the few lines between the invoker and {@see GraphRuns}, which turns it into what it really is —
 * a line in the run's log and a result the caller reads. A host that drives a {@see NodeInvoker} with its own
 * {@see \Milpa\Orchestrator\ProcessRunner} catches it there.
 */
final class NodeRefused extends \RuntimeException
{
    /**
     * @param string       $node      the state whose node did not run
     * @param string       $operation the operation bound to it
     * @param list<string> $needs     what the node declared: its scopes, or its one permission
     * @param string|null  $answer    the gate answer that was not taken because it leads here, when that is the case
     */
    public function __construct(
        public readonly string $node,
        public readonly string $operation,
        public readonly array $needs,
        string $message,
        public readonly ?string $answer = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The node the run is standing on was about to run, and did not. The run stays parked there.
     *
     * @param string $why the fragment {@see Caller::refusalOf()} gave — «it needs …»
     */
    public static function at(string $state, Operation $node, string $why): self
    {
        return new self(
            $state,
            $node->name,
            self::needsOf($node),
            "The node '{$state}' ({$node->name}) did not run: {$why}. The run is parked there and nothing after it ran.",
        );
    }

    /**
     * An answer to a gate leads straight to a node this caller may not run, so the answer is not taken.
     *
     * @param string $why the fragment {@see Caller::refusalOf()} gave — «it needs …»
     */
    public static function ahead(string $decision, string $state, Operation $node, string $why): self
    {
        return new self(
            $state,
            $node->name,
            self::needsOf($node),
            "The answer '{$decision}' was not recorded: " . self::leadsTo($state, $node, $why) . '. The gate is still waiting.',
            $decision,
        );
    }

    /**
     * Why an option that leads to `$state` cannot be taken by a caller who may not run its node — the same words
     * whether it is said BEFORE anybody answers ({@see GraphRuns::pending()}) or when an answer is refused.
     *
     * @param string $why the fragment {@see Caller::refusalOf()} gave — «it needs …»
     */
    public static function leadsTo(string $state, Operation $node, string $why): string
    {
        return "it leads to the node '{$state}' ({$node->name}), and {$why}";
    }

    /**
     * What a node declared it needs: its scopes, or its one permission.
     *
     * @return list<string>
     */
    public static function needsOf(Operation $node): array
    {
        return $node->permission !== null ? [$node->permission] : $node->scopes;
    }
}
