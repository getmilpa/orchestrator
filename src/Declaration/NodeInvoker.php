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

use Milpa\Orchestrator\NodeInvokerInterface;

/**
 * Runs the node bound to a state, writes what it produced, and takes the edge its answer names.
 *
 * Four things happen here and each one is derived from a declaration rather than configured:
 *
 *  - **whether it may run** is what the node's own `#[Needs]` says, judged against whoever is driving the run right
 *    now ({@see Caller}) — a node the root's scope reached is not thereby a node its caller may run. A refused node
 *    is not called at all ({@see NodeRefused});
 *  - **the input** is projected from the channels by parameter NAME, so no node declares a mapping;
 *  - **what it produced** is written into the log under the channel names its result object already
 *    uses, so the artifact lives in the process's own memory instead of in whoever drove it;
 *  - **the edge** is the case of the routing enum the node returned — the same mechanism a human's
 *    gate answer uses, which is the whole point.
 *
 * The node is called the way a surface calls every handler — its input, who to attribute the call to, and the
 * caller's authority — so a `run()` that asks for its `InvocationContext` receives the one of the invocation that
 * reached it. Who that was is written into the same event the node's channels are ({@see self::RAN}).
 *
 * The loop budget is counted FROM THE LOG, not from a counter someone maintains: the count rides in
 * the appended payload, so a replay of the same events reaches the same decision. When the budget is
 * spent the run does not stop — it takes the edge to the gate that asks a person what to do next.
 */
final readonly class NodeInvoker implements NodeInvokerInterface
{
    /** The reserved channel the loop budget is counted in. */
    public const string TAKEN = '_taken';

    /** The reserved key under which each node's event says which node ran, and who ran it. */
    public const string RAN = '_ran';

    /**
     * @param Caller $caller who is driving the run for this advance. The default is nobody — no context to hand a
     *                       node and no authority to judge its needs against, so only nodes that declare none run
     */
    public function __construct(private CompiledGraph $graph, private Caller $caller = new Caller())
    {
    }

    /**
     * Runs the node bound to `$state`, writes what it produced, and returns the edge it takes.
     *
     * @param array<string, mixed>                  $context
     * @param list<array{name: string, to: string}> $transitions
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     *
     * @throws NodeRefused when the caller driving the run does not hold what the node declares it needs
     */
    public function invoke(string $state, array $context, array $transitions): ?array
    {
        $operation = $this->graph->operations[$state] ?? null;

        if ($operation === null) {
            return null; // a gate state has no node: the engine's own behaviour stands.
        }

        $why = $this->caller->refusalOf($operation);

        if ($why !== null) {
            throw NodeRefused::at($state, $operation, $why);
        }

        $result = ($operation->handler)(
            $this->inputFor($operation->inputSchema, $context),
            $this->caller->context,
            $this->caller->authority,
        );
        $payload = $this->channelsFrom($result, $context);
        $payload[self::RAN] = ['node' => $state, 'operation' => $operation->name, 'by' => $this->caller->record()];
        $table = $this->graph->routing[$state] ?? null;

        if ($table === null) {
            return [$transitions[0]['name'], $payload];
        }

        return [$this->edgeFrom($state, $table, $result, $context, $payload), $payload];
    }

    /**
     * @param array<string, mixed>|null $schema
     * @param array<string, mixed>      $context
     *
     * @return array<string, mixed>
     */
    private function inputFor(?array $schema, array $context): array
    {
        $input = [];

        foreach (array_keys($schema['properties'] ?? []) as $name) {
            if (\array_key_exists($name, $context)) {
                $input[$name] = $context[$name];
            }
        }

        return $input;
    }

    /**
     * What the node produced, as channel writes — enums flattened to their values so the log stays
     * plain data and a replay years later needs no class to read it.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function channelsFrom(array $result, array $context): array
    {
        $payload = [];

        foreach ($result as $channel => $value) {
            if (\array_key_exists($channel, $this->graph->channels)) {
                $payload[$channel] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }

        foreach ($this->graph->appends as $channel => $source) {
            if (\array_key_exists($source, $result)) {
                /** @var list<mixed> $existing */
                $existing = \is_array($context[$channel] ?? null) ? $context[$channel] : [];
                $existing[] = $result[$source];
                $payload[$channel] = $existing;
            }
        }

        return $payload;
    }

    /**
     * @param array{enum: class-string, map: array<string, string>} $table
     * @param array<string, mixed>                                  $result
     * @param array<string, mixed>                                  $context
     * @param array<string, mixed>                                  $payload
     */
    private function edgeFrom(string $state, array $table, array $result, array $context, array &$payload): string
    {
        $enum = $table['enum'];
        $case = null;

        foreach ($result as $value) {
            if ($value instanceof $enum) {
                $case = $value;
                break;
            }
        }

        if ($case === null) {
            throw new \RuntimeException(sprintf(
                "The node at state '%s' routes on %s but returned no value of it. A node that decides has to say what it decided.",
                $state,
                $enum,
            ));
        }

        $code = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
        $budget = $this->graph->budgets[$code] ?? null;

        if ($budget === null) {
            return $code;
        }

        /** @var array<string, int> $taken */
        $taken = \is_array($context[self::TAKEN] ?? null) ? $context[self::TAKEN] : [];
        $sofar = (int) ($taken[$code] ?? 0);

        if ($sofar >= $budget) {
            return $this->graph->exhausted[$code];
        }

        $taken[$code] = $sofar + 1;
        $payload[self::TAKEN] = $taken;

        return $code;
    }
}
