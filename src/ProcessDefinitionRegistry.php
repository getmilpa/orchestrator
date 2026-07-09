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

/**
 * Maps a process definition's name to its built {@see ProcessDefinition} — the directory a
 * consumer registers every process it wants callable by name (typically at boot) and the
 * `process_instantiate` tool (see {@see \Milpa\Orchestrator\Tools\ProcessInstantiateTool})
 * resolves through when a caller names a definition to start.
 */
final class ProcessDefinitionRegistry
{
    /** @var array<string, ProcessDefinition> */
    private array $definitions = [];

    /**
     * Registers `$definition` under `$name`, overwriting any prior registration for the same
     * name.
     */
    public function register(string $name, ProcessDefinition $definition): void
    {
        $this->definitions[$name] = $definition;
    }

    /**
     * Whether a definition is registered under `$name`.
     */
    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * The definition registered under `$name`.
     *
     * @throws \RuntimeException when no definition is registered under `$name`
     */
    public function get(string $name): ProcessDefinition
    {
        return $this->definitions[$name] ?? throw new \RuntimeException("ProcessDefinitionRegistry: no process definition registered under '{$name}'.");
    }

    /**
     * Every registered definition's name, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->definitions);
    }
}
