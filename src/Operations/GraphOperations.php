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
use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Orchestrator\Declaration\GraphRuns;

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
     * The five graph operations, each projected to every surface this app exposes.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        $resolve = fn (string $type): object => $this->container->get($type);

        return [
            DeclaredOperation::from(ListGraphs::class, $resolve),
            DeclaredOperation::from(StartGraph::class, $resolve),
            DeclaredOperation::from(PendingDecisions::class, $resolve),
            DeclaredOperation::from(DecideGraph::class, $resolve),
            DeclaredOperation::from(ShowGraphRun::class, $resolve),
        ];
    }
}
