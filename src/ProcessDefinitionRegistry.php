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
 * resolves through when a caller names a definition to start. Also the seam {@see ProcessRunner}
 * resolves a {@see SubprocessSpec::$definitionRef} through at runtime when entering a subprocess
 * state.
 *
 * `register()` validates the definition-REFERENCE graph (which name's subprocess states point at
 * which other names, transitively) stays acyclic — a process cannot, directly or through any
 * chain of subprocess states, end up waiting on itself. This is a STATIC, name-level check,
 * independent of {@see ProcessRunner}'s own runtime depth limit (which guards the same failure
 * mode at the instance level, in case a cycle somehow slips past this check — e.g. a definition
 * mutated after registration).
 */
final class ProcessDefinitionRegistry
{
    /** @var array<string, ProcessDefinition> */
    private array $definitions = [];

    /** @var array<string, list<string>> every registered name's OWN subprocess `definitionRef`s */
    private array $subprocessRefs = [];

    /**
     * Registers `$definition` under `$name`, overwriting any prior registration for the same
     * name. Rolled back entirely (the registry ends up exactly as it was before this call) when
     * the resulting definition-reference graph turns out cyclic, so a caller that catches the
     * exception and keeps using this registry is not left with a partially-corrupted graph.
     *
     * @throws \RuntimeException when this registration completes a cycle in the
     *                           definition-reference graph (`$name`, directly or transitively
     *                           through any subprocess state, references itself)
     */
    public function register(string $name, ProcessDefinition $definition): void
    {
        $previousDefinition = $this->definitions[$name] ?? null;
        $previousRefs = $this->subprocessRefs[$name] ?? null;

        $this->definitions[$name] = $definition;
        $this->subprocessRefs[$name] = $this->subprocessRefsOf($definition);

        try {
            $this->assertAcyclicDefinitionGraph();
        } catch (\RuntimeException $e) {
            if ($previousDefinition !== null && $previousRefs !== null) {
                $this->definitions[$name] = $previousDefinition;
                $this->subprocessRefs[$name] = $previousRefs;
            } else {
                unset($this->definitions[$name], $this->subprocessRefs[$name]);
            }

            throw $e;
        }
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

    /**
     * Every `SubprocessSpec::$definitionRef` declared anywhere in `$definition`, one per
     * subprocess state (duplicates possible, and fine — this is an edge list, not a set).
     *
     * @return list<string>
     */
    private function subprocessRefsOf(ProcessDefinition $definition): array
    {
        $refs = [];
        foreach ($definition->states() as $state) {
            $spec = $definition->subprocessFor($state);
            if ($spec !== null) {
                $refs[] = $spec->definitionRef;
            }
        }

        return $refs;
    }

    /**
     * Runs a DFS from every currently-registered name over {@see self::$subprocessRefs},
     * throwing the moment it revisits a name still on the current path. A reference to a name NOT
     * (yet) registered is simply a dead end for this check — it cannot participate in a cycle
     * until it is itself registered, at which point THIS method runs again and would catch the
     * cycle then.
     *
     * @throws \RuntimeException when a cycle is found
     */
    private function assertAcyclicDefinitionGraph(): void
    {
        $visiting = [];
        $visited = [];
        foreach (array_keys($this->subprocessRefs) as $name) {
            $this->assertNoCycleFrom($name, $visiting, $visited);
        }
    }

    /**
     * @param array<string, true> $visiting
     * @param array<string, true> $visited
     */
    private function assertNoCycleFrom(string $name, array &$visiting, array &$visited): void
    {
        if (isset($visited[$name])) {
            return;
        }
        if (isset($visiting[$name])) {
            throw new \RuntimeException("ProcessDefinitionRegistry: the definition-reference graph has a cycle revisiting '{$name}' — a subprocess definition references itself, directly or transitively.");
        }

        $visiting[$name] = true;
        foreach ($this->subprocessRefs[$name] ?? [] as $next) {
            if (isset($this->subprocessRefs[$next])) {
                $this->assertNoCycleFrom($next, $visiting, $visited);
            }
        }
        unset($visiting[$name]);
        $visited[$name] = true;
    }
}
