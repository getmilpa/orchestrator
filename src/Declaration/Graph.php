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
 * The class IS the graph, and its constructor IS the state.
 *
 * A graph here is not a new kind of thing: it is a DECLARATION THAT COMPOSES OPERATIONS. Each node
 * names an operation that already exists — with its gates, its consent, its effect ceiling and its
 * attribution — and the graph declares only the wiring, the channels and where it stops. Nothing
 * about a node has to be restated to put it in a graph.
 *
 * ```php
 * #[Graph(name: 'essay:review', description: 'Draft against a rubric until it passes.')]
 * #[Start(Write::class)]
 * #[Edge(from: Write::class, to: Grade::class)]
 * #[Route(when: Verdict::Passed, to: Publish::class)]
 * #[Route(when: Verdict::Failed, to: Write::class, atMost: 3, thenAsk: EditorCall::class)]
 * final readonly class EssayReview
 * {
 *     public function __construct(
 *         #[Target] public string $title,
 *         public string $rubric,
 *         public string $draft = '',
 *     ) {
 *     }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Graph
{
    /**
     * @param string      $name        How a human and an agent both call this graph (`domain:verb`).
     * @param string      $description What it does, in one sentence — one truth, two readers.
     * @param string|null $version     The contract version, when this graph has one.
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $version = null,
    ) {
    }
}
