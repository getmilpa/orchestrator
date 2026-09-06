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
 * The graphs an app declares — the list a reader can see in a diff.
 *
 * Registration is by CLASS, and compilation is lazy: a graph is compiled the first time somebody
 * asks for it, so a malformed declaration fails when it is used and names itself, instead of taking
 * the whole app down at boot for a graph nobody ran. The compiled result is kept, because compiling
 * is reflection and a process may be advanced many times.
 */
final class GraphRegistry
{
    /** @var array<string, class-string> */
    private array $classes = [];

    /** @var array<string, CompiledGraph> */
    private array $compiled = [];

    /** @param \Closure|null $resolve `fn (string $type): object` — how every node's collaborators are found */
    public function __construct(private readonly ?\Closure $resolve = null)
    {
    }

    /**
     * Declares a graph class. The name is the one its own `#[Graph]` carries.
     *
     * @param class-string $class
     *
     * @throws GraphDeclarationException when the class is not a declared graph, or the name is taken
     */
    public function register(string $class): self
    {
        if (!DeclaredGraph::isDeclared($class)) {
            throw new GraphDeclarationException("Cannot register {$class}: it carries no #[Graph] attribute.");
        }

        $name = $this->nameOf($class);

        if (isset($this->classes[$name]) && $this->classes[$name] !== $class) {
            throw new GraphDeclarationException(
                "Two graphs both call themselves '{$name}': {$this->classes[$name]} and {$class}. A name is how a human and an agent both reach it, so it has one owner."
            );
        }

        $this->classes[$name] = $class;

        return $this;
    }

    /**
     * The names of every graph this app declared.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    /** Is a graph declared under this name? */
    public function has(string $name): bool
    {
        return isset($this->classes[$name]);
    }

    /**
     * The compiled graph carrying this name, compiled on first use and kept afterwards.
     *
     * @throws GraphDeclarationException when no graph carries that name
     */
    public function get(string $name): CompiledGraph
    {
        if (!isset($this->classes[$name])) {
            throw new GraphDeclarationException(
                "No graph named '{$name}'. Declared: " . (implode(', ', $this->names()) ?: 'none') . '.'
            );
        }

        return $this->compiled[$name] ??= DeclaredGraph::from($this->classes[$name], $this->resolve);
    }

    /** @param class-string $class */
    private function nameOf(string $class): string
    {
        $attribute = (new \ReflectionClass($class))->getAttributes(Graph::class)[0]->newInstance();

        return $attribute->name;
    }
}
