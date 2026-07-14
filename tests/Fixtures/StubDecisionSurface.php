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

namespace Milpa\Orchestrator\Tests\Fixtures;

use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Orchestrator\DecisionSurfaceInterface;

/**
 * Minimal, domain-free {@see DecisionSurfaceInterface} implementation for this package's own
 * tests — `$options` is passed in directly rather than derived from a domain entity, so a test
 * can build both a MATCHING surface (whose options equal a gate's transitions) and a
 * DELIBERATELY MISMATCHED one (to prove {@see \Milpa\Orchestrator\PendingDecision}'s 1:1
 * invariant throws). Real consumers ship a real domain-rendering implementation instead — see
 * {@see DecisionSurfaceInterface}'s own docblock.
 */
final readonly class StubDecisionSurface implements DecisionSurfaceInterface
{
    /**
     * @param list<string> $options
     */
    public function __construct(private array $options)
    {
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * This stub's runtime contract: no props, no declared actions.
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'stub-decision-surface',
            contractVersion: '0.1.0',
            summary: 'Test-only decision surface with caller-supplied options.',
        );
    }

    /**
     * Builds this stub's initial state: just the offered options.
     *
     * @param array<string, mixed> $props
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $contract = self::contract();

        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: $contract->name,
            version: $contract->contractVersion,
            data: ['options' => $this->options],
        );
    }

    /**
     * Unused by any test — this stub never receives an interaction.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
