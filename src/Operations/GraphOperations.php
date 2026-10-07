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

namespace Milpa\Orchestrator\Operations;

use Milpa\Command\CommandProvider;
use Milpa\Command\Declaration\DeclarationException;
use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * The door every surface reaches a declared graph through.
 *
 * These are ordinary declared operations, so `graph:start` is a `coa` command, an MCP tool and an
 * HTTP route from one declaration — and the Desktop reads `graph:pending` through the same door a
 * terminal does. That is the point of putting them here rather than building a bespoke API: a run
 * parked in a log is answerable from wherever the human happens to be.
 *
 * List it in an app's `config/operations.php`. It needs a {@see GraphRuns} in the container, which
 * is where the app says WHICH graphs it declares.
 */
final class GraphOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The six graph operations, each projected to every surface this app exposes.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        $resolve = fn (string $type): object => $this->container->get($type);

        return [
            DeclaredOperation::from(ListGraphs::class, $resolve),
            $this->driving(StartGraph::class, $resolve),
            DeclaredOperation::from(PendingDecisions::class, $resolve),
            $this->driving(DecideGraph::class, $resolve),
            $this->driving(ResumeGraph::class, $resolve),
            DeclaredOperation::from(ShowGraphRun::class, $resolve),
        ];
    }

    /**
     * A declared operation that drives a run, given the handler a driver needs.
     *
     * Everything a surface reads — the name, the schema, the scopes, the effects — is still derived from the class.
     * Only the handler differs: a declared `run()` is handed the context and never the authority, because an
     * operation attributes and the policy authorizes. A graph is the exception that rule already names — it
     * originates further governed calls, one per node — so its handler takes the third argument every surface
     * passes, and hands both to the run as its {@see Caller}.
     *
     * @param class-string<StartGraph|DecideGraph|ResumeGraph> $class
     */
    private function driving(string $class, \Closure $resolve): Operation
    {
        $declared = DeclaredOperation::from($class, $resolve);
        $declaredInputs = array_flip(array_keys($declared->inputSchema['properties'] ?? []));
        $required = $declared->inputSchema['required'] ?? [];

        $handler = function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null) use ($class, $declaredInputs, $required): array {
            foreach ($required as $name) {
                if (!\array_key_exists($name, $input)) {
                    // The sentence the declared handler says, so a caller sees one refusal whichever handler it reached.
                    throw new DeclarationException("Operation {$class}: missing required input '{$name}'.");
                }
            }

            return (new $class(...array_intersect_key($input, $declaredInputs)))
                ->runAs($this->container->get(GraphRuns::class), new Caller($context, $authority));
        };

        return new Operation(...array_merge(get_object_vars($declared), ['handler' => $handler]));
    }
}
