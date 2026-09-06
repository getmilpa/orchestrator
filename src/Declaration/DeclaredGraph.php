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

use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Command\Operation;
use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;

/**
 * Compiles a declared graph into the process definition the engine already runs.
 *
 * The rule this implements is the one the whole house is built on, applied one level up: **what
 * changes authority is DECLARED; what a type already says is DERIVED.** The wiring, the loop budget
 * and who may answer a gate are declared, because nothing can infer them. Everything else is read
 * off types that already exist:
 *
 *  - the graph's constructor IS its state — each promoted property is a channel;
 *  - each node is an ordinary `#[Operation]`, so its input schema, its effect ceiling, its consent
 *    and its scopes come along without being restated;
 *  - the routing enum's CASES are the outgoing edges, so a model's verdict and a human's gate choice
 *    are written the same way;
 *  - a node with no outgoing edge is terminal — there is no such thing here as a state with nowhere
 *    to go, which is the silent dead end the engine used to tolerate.
 *
 * Nothing is guessed. Every refusal below names the class and says what to declare, once, at compile
 * time — never on the first request in production.
 */
final class DeclaredGraph
{
    /** Does this class declare itself a graph? */
    public static function isDeclared(string $class): bool
    {
        return class_exists($class) && (new \ReflectionClass($class))->getAttributes(Graph::class) !== [];
    }

    /**
     * Compiles the graph declared by `$class`.
     *
     * @param class-string  $class   the declaring class
     * @param \Closure|null $resolve `fn (string $type): object` — how each node's collaborators are found
     *
     * @throws GraphDeclarationException when the declaration cannot be compiled without guessing
     */
    public static function from(string $class, ?\Closure $resolve = null): CompiledGraph
    {
        if (!class_exists($class)) {
            throw new GraphDeclarationException("Cannot compile a graph from '{$class}': the class does not exist.");
        }

        $reflection = new \ReflectionClass($class);
        $graph = self::attribute($reflection, Graph::class);

        if (!$graph instanceof Graph) {
            throw new GraphDeclarationException(
                "Class {$class} is not a declared graph: add #[Graph(name: '…', description: '…')]."
            );
        }

        $start = self::attribute($reflection, Start::class);
        if (!$start instanceof Start) {
            throw new GraphDeclarationException("Graph {$class} declares no #[Start]: name the node it begins at.");
        }

        /** @var list<Edge> $edges */
        $edges = self::attributes($reflection, Edge::class);
        /** @var list<Route> $routes */
        $routes = self::attributes($reflection, Route::class);
        /** @var list<Ask> $asks */
        $asks = self::attributes($reflection, Ask::class);

        [$channels, $appends] = self::channelsOf($reflection, $class);

        // ── The nodes. Every class named anywhere in the wiring is compiled as an operation.
        $nodeClasses = [$start->node];
        foreach ($edges as $edge) {
            $nodeClasses[] = $edge->from;
            $nodeClasses[] = $edge->to;
        }
        foreach ($routes as $route) {
            $nodeClasses[] = $route->to;
            if ($route->from !== null) {
                $nodeClasses[] = $route->from;
            }
        }
        $nodeClasses = array_values(array_unique($nodeClasses));

        /** @var array<string, Operation> $operations */
        $operations = [];
        /** @var array<string, class-string> $stateOfClass */
        $stateOfClass = [];
        foreach ($nodeClasses as $nodeClass) {
            $operation = self::operationOf($nodeClass, $class, $resolve);
            $state = self::stateCode($operation->name);
            if (isset($operations[$state])) {
                throw new GraphDeclarationException(
                    "Graph {$class}: two nodes compile to the same state '{$state}'. Operation names must differ after the colon."
                );
            }
            $operations[$state] = $operation;
            $stateOfClass[$nodeClass] = $state;
        }

        $startState = $stateOfClass[$start->node];

        self::assertChannelsCover($class, $operations, $channels);
        self::assertNoUnsupportedConsent($class, $operations);

        // ── The routing tables, and the gate states the budgets escalate to.
        [$routing, $budgets, $exhausted, $askStates] = self::routingOf(
            $class,
            $routes,
            $asks,
            $stateOfClass,
            $operations,
        );

        // ── The state definitions. A state with no way out is terminal, and that is derived.
        $outgoing = self::outgoingCount($edges, $routing, $stateOfClass);
        $states = [];
        $endings = [];

        foreach ($operations as $code => $operation) {
            // A NODE ALWAYS RUNS; A TERMINAL IS A PLACE, NOT A NODE.
            //
            // `advance()` checks `isTerminal` BEFORE it invokes anything, so a node marked terminal
            // because nothing leaves it would never execute — the graph would «finish» at a publish
            // step that never published. Measured on the first run of this compiler. So a node with
            // no declared way out gets one: an edge to a derived ending, which is where the machine
            // actually stops.
            $states[$code] = self::state($graph->name, $code, $operation->description, $code === $startState, false);

            if (($outgoing[$code] ?? 0) === 0) {
                $ending = $code . '_done';
                $states[$ending] = self::state($graph->name, $ending, 'Finished at ' . $code, false, true);
                $endings[$code] = $ending;
            }
        }
        foreach ($askStates as $code => $ask) {
            $states[$code] = self::state($graph->name, $code, $ask->because, false, false);
        }

        // ── The transitions.
        $transitions = [];
        foreach ($endings as $from => $ending) {
            $transitions[] = self::transition($graph->name, $ending, $states[$from], $states[$ending]);
        }

        foreach ($edges as $edge) {
            $from = $stateOfClass[$edge->from];
            $to = $stateOfClass[$edge->to];
            $transitions[] = self::transition($graph->name, $to, $states[$from], $states[$to]);
        }

        foreach ($routes as $route) {
            $from = self::originOf($route, $class, $operations, $stateOfClass, $askStates);
            $to = $stateOfClass[$route->to];
            $code = self::caseValue($route->when);
            $transition = self::transition($graph->name, $code, $states[$from], $states[$to]);

            if ($route->atMost !== null) {
                if ($route->atMost < 1) {
                    throw new GraphDeclarationException(
                        "Graph {$class}: route '{$code}' declares atMost: {$route->atMost}. A budget of zero is not a loop, it is an edge that cannot be taken."
                    );
                }
                if ($route->thenAsk === null) {
                    throw new GraphDeclarationException(
                        "Graph {$class}: route '{$code}' declares atMost: {$route->atMost} but no thenAsk. A budget that simply stops leaves the run somewhere nobody chose — name the options a human is offered when it runs out."
                    );
                }
                $transition->setMetadata(['at_most' => $route->atMost, 'then' => self::enumState($route->thenAsk)]);
            }

            $transitions[] = $transition;
        }

        // ── Each budget gets its escape: an edge to the gate that asks a human what to do next.
        foreach ($exhausted as $code => $target) {
            $from = self::stateOfRoute($code, $routing);
            $transitions[] = self::transition($graph->name, $target, $states[$from], $states[$target]);
        }

        // ── The gates. Every transition leaving an asked state carries the same gate definition.
        foreach ($askStates as $code => $ask) {
            $gate = (new GateDefinition())
                ->setDomain($graph->name)
                ->setCode($code . '_gate')
                ->setName($code)
                ->setDescription($ask->because)
                ->setRequesterRole('process')
                ->setApproverRole($ask->of)
                ->setApprovalPolicy($ask->policy)
                ->setIsWaivable($ask->waivable);

            if ($ask->evidence !== []) {
                $gate->setRequiredEvidenceTypes($ask->evidence);
            }

            foreach ($transitions as $transition) {
                if ($transition->getFromState()?->getCode() === $code) {
                    $transition->addGateDefinition($gate);
                }
            }
        }

        try {
            $definition = new ProcessDefinition(array_values($states), $transitions);
        } catch (\RuntimeException $e) {
            throw new GraphDeclarationException("Graph {$class} does not compile: " . $e->getMessage(), 0, $e);
        }

        return new CompiledGraph(
            $graph->name,
            $graph->description,
            $definition,
            $operations,
            $routing,
            $channels,
            $appends,
            $budgets,
            $exhausted,
        );
    }

    /**
     * A node that declares it needs confirming is REFUSED until this compiler can insert that pause.
     *
     * The alternative was to compile the graph and drop the requirement, which would take an
     * operation that says «a human confirms this before it runs» and quietly run it without one. A
     * missing feature that says so is a gap; a missing feature that stays silent is a lie about
     * authority. The consent gate is a declared next slice, and this refusal is what keeps the two
     * honest until it lands.
     *
     * @param array<string, Operation> $operations
     */
    private static function assertNoUnsupportedConsent(string $class, array $operations): void
    {
        foreach ($operations as $state => $operation) {
            if ($operation->requiresConfirmation) {
                throw new GraphDeclarationException(sprintf(
                    "Graph %s: node '%s' declares #[Confirms], and this compiler cannot yet insert the consent pause it asks for. Compiling anyway would run an operation that demands confirmation without one — so it refuses instead. Remove the node from the graph, or run it outside one, until the derived consent gate lands.",
                    $class,
                    $state,
                ));
            }
        }
    }

    /**
     * Every input a node asks for must be a channel of this graph.
     *
     * Without this, a node parameter naming no channel silently arrives as nothing at run time — the
     * same silent null the subprocess wiring already produces — and the graph looks like it works
     * until the day the value mattered.
     *
     * @param array<string, Operation> $operations
     * @param array<string, mixed>     $channels
     */
    private static function assertChannelsCover(string $class, array $operations, array $channels): void
    {
        foreach ($operations as $state => $operation) {
            foreach (array_keys($operation->inputSchema['properties'] ?? []) as $name) {
                if (!\array_key_exists($name, $channels)) {
                    throw new GraphDeclarationException(sprintf(
                        "Graph %s: node '%s' reads \$%s, which is not a channel of this graph. Declare it in the graph's constructor, or the node would receive nothing and nobody would notice.",
                        $class,
                        $state,
                        $name,
                    ));
                }
            }
        }
    }

    /**
     * The graph's constructor IS its state: one promoted property, one channel.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function channelsOf(\ReflectionClass $reflection, string $class): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            throw new GraphDeclarationException(
                "Graph {$class} has no constructor. The constructor IS the state — declare the channels the nodes read and write."
            );
        }

        $channels = [];
        $appends = [];

        foreach ($constructor->getParameters() as $parameter) {
            if (!$parameter->isPromoted()) {
                continue;
            }
            $name = $parameter->getName();
            $channels[$name] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

            $accumulates = $parameter->getAttributes(Appends::class);
            if ($accumulates !== []) {
                $appends[$name] = $accumulates[0]->newInstance()->of;
            }
        }

        if ($channels === []) {
            throw new GraphDeclarationException("Graph {$class} declares no channels: its constructor has no promoted properties.");
        }

        return [$channels, $appends];
    }

    /** @param class-string $nodeClass */
    private static function operationOf(string $nodeClass, string $graphClass, ?\Closure $resolve): Operation
    {
        try {
            return DeclaredOperation::from($nodeClass, $resolve);
        } catch (\InvalidArgumentException $e) {
            throw new GraphDeclarationException(
                "Graph {$graphClass}: node {$nodeClass} is not a usable operation — " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * @param list<Route>              $routes
     * @param list<Ask>                $asks
     * @param array<string, string>    $stateOfClass
     * @param array<string, Operation> $operations
     *
     * @return array{0: array<string, array{enum: class-string, map: array<string, string>}>, 1: array<string, int>, 2: array<string, string>, 3: array<string, Ask>}
     */
    private static function routingOf(string $class, array $routes, array $asks, array $stateOfClass, array $operations): array
    {
        $askStates = [];
        foreach ($asks as $ask) {
            if (!enum_exists($ask->options)) {
                throw new GraphDeclarationException("Graph {$class}: #[Ask] names {$ask->options}, which is not an enum.");
            }
            $askStates[self::enumState($ask->options)] = $ask;
        }

        $routing = [];
        $budgets = [];
        $exhausted = [];

        foreach ($routes as $route) {
            $enum = $route->when::class;
            $from = self::originOf($route, $class, $operations, $stateOfClass, $askStates);
            $code = self::caseValue($route->when);

            $routing[$from] ??= ['enum' => $enum, 'map' => []];
            if ($routing[$from]['enum'] !== $enum) {
                throw new GraphDeclarationException(
                    "Graph {$class}: state '{$from}' routes on two different enums ({$routing[$from]['enum']} and {$enum}). One decision, one enum."
                );
            }
            $routing[$from]['map'][$code] = $code;

            if ($route->atMost !== null && $route->thenAsk !== null) {
                $budgets[$code] = $route->atMost;
                $exhausted[$code] = self::enumState($route->thenAsk);
            }
        }

        // Exhaustiveness: every case of every routing enum must have somewhere to go.
        foreach ($routing as $state => $table) {
            $enum = $table['enum'];
            foreach ($enum::cases() as $case) {
                if (!isset($table['map'][self::caseValue($case)])) {
                    throw new GraphDeclarationException(sprintf(
                        "Graph %s: state '%s' routes on %s but declares no #[Route] for case %s. Every case must have somewhere to go, or a run reaches it at 2am and stops nowhere.",
                        $class,
                        $state,
                        $enum,
                        $case->name,
                    ));
                }
            }
        }

        return [$routing, $budgets, $exhausted, $askStates];
    }

    /**
     * A route's origin, derived: exactly one node returns that enum, so `from:` is only written
     * when two do and the answer is genuinely ambiguous.
     *
     * @param array<string, Operation> $operations
     * @param array<string, string>    $stateOfClass
     * @param array<string, Ask>       $askStates
     */
    private static function originOf(Route $route, string $class, array $operations, array $stateOfClass, array $askStates): string
    {
        if ($route->from !== null) {
            // No guard here on purpose: a `from:` is collected into the node set before anything is
            // compiled, so a class that is not a node of this graph has already been refused for the
            // real reason — it is not a declared operation. A second check would be unreachable.
            return $stateOfClass[$route->from];
        }

        $enum = $route->when::class;

        foreach ($askStates as $code => $ask) {
            if ($ask->options === $enum) {
                return $code;
            }
        }

        $producers = [];
        foreach ($stateOfClass as $nodeClass => $state) {
            if (self::producesEnum($nodeClass, $enum)) {
                $producers[] = $state;
            }
        }

        if ($producers === []) {
            throw new GraphDeclarationException(
                "Graph {$class}: #[Route(when: {$enum}::…)] has no origin — no node returns a {$enum} and no #[Ask] offers it. Say where the decision comes from."
            );
        }

        if (\count($producers) > 1) {
            throw new GraphDeclarationException(sprintf(
                "Graph %s: %s is returned by more than one node (%s), so the origin of its routes cannot be derived. Write from: on each #[Route].",
                $class,
                $enum,
                implode(', ', $producers),
            ));
        }

        return $producers[0];
    }

    /**
     * Does this node's RETURN TYPE carry a value of `$enum`?
     *
     * Read off the type, not off the derived schema: a JSON schema flattens an enum to its admissible
     * values and loses which enum they came from, and «which enum» is exactly the question here.
     *
     * @param class-string $nodeClass
     */
    private static function producesEnum(string $nodeClass, string $enum): bool
    {
        $run = (new \ReflectionClass($nodeClass))->getMethod('run');
        $type = $run->getReturnType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        $returned = $type->getName();
        if ($returned === $enum) {
            return true;
        }

        if (!class_exists($returned)) {
            return false;
        }

        $constructor = (new \ReflectionClass($returned))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $parameterType = $parameter->getType();
            if ($parameter->isPromoted()
                && $parameterType instanceof \ReflectionNamedType
                && $parameterType->getName() === $enum) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array{enum: class-string, map: array<string, string>}> $routing */
    private static function stateOfRoute(string $code, array $routing): string
    {
        foreach ($routing as $state => $table) {
            if (isset($table['map'][$code])) {
                return $state;
            }
        }

        throw new GraphDeclarationException("No state routes on '{$code}'.");
    }

    /**
     * How many ways out each state has — and a state with none is TERMINAL, derived rather than declared.
     *
     * It reads the ALREADY-RESOLVED routing table rather than the raw routes, because a route's origin
     * is usually derived; counting the raw declarations would mark every routing node as a dead end.
     *
     * @param list<Edge>                                                           $edges
     * @param array<string, array{enum: class-string, map: array<string, string>}> $routing
     * @param array<string, string>                                                $stateOfClass
     *
     * @return array<string, int>
     */
    private static function outgoingCount(array $edges, array $routing, array $stateOfClass): array
    {
        $counts = [];

        foreach ($edges as $edge) {
            $from = $stateOfClass[$edge->from];
            $counts[$from] = ($counts[$from] ?? 0) + 1;
        }

        foreach ($routing as $state => $table) {
            $counts[$state] = ($counts[$state] ?? 0) + \count($table['map']);
        }

        return $counts;
    }

    private static function state(string $domain, string $code, ?string $label, bool $initial, bool $terminal): StateDefinition
    {
        return (new StateDefinition())
            ->setDomain($domain)
            ->setCode($code)
            ->setLabel($label !== null ? mb_substr($label, 0, 120) : $code)
            ->setSortOrder(0)
            ->setIsInitial($initial)
            ->setIsTerminal($terminal);
    }

    private static function transition(string $domain, string $code, StateDefinition $from, StateDefinition $to): TransitionDefinition
    {
        return (new TransitionDefinition())
            ->setDomain($domain)
            ->setCode($code)
            ->setLabel($code)
            ->setFromState($from)
            ->setToState($to);
    }

    /** `essay:write` → `write`. The colon separates the domain a human types from the state a graph walks. */
    private static function stateCode(string $operationName): string
    {
        $position = strrpos($operationName, ':');

        return $position === false ? $operationName : substr($operationName, $position + 1);
    }

    /** `App\Essay\EditorCall` → `editor_call`. */
    private static function enumState(string $enum): string
    {
        $segments = explode('\\', $enum);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', end($segments)));
    }

    private static function caseValue(\UnitEnum $case): string
    {
        return $case instanceof \BackedEnum ? (string) $case->value : $case->name;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<object>
     */
    private static function attributes(\ReflectionClass $reflection, string $attribute): array
    {
        return array_map(
            static fn (\ReflectionAttribute $found): object => $found->newInstance(),
            $reflection->getAttributes($attribute),
        );
    }

    /** @param \ReflectionClass<object> $reflection */
    private static function attribute(\ReflectionClass $reflection, string $attribute): ?object
    {
        $found = $reflection->getAttributes($attribute);

        return $found === [] ? null : $found[0]->newInstance();
    }
}
