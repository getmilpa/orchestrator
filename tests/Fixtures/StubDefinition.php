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

namespace Milpa\Orchestrator\Tests\Fixtures;

use Milpa\Orchestrator\DefinitionContract;

/**
 * Minimal hand-built {@see DefinitionContract}: `draft --submit--> review`, nothing beyond it.
 * Stands in for a real {@see \Milpa\Orchestrator\ProcessDefinition} so {@see
 * \Milpa\Orchestrator\Reducer} can be proven against the interface's shape directly, without a
 * `milpa/workflow` composition in the way.
 */
final class StubDefinition implements DefinitionContract
{
    public function initialState(): string
    {
        return 'draft';
    }

    /**
     * @return list<array{name: string, to: string}>
     */
    public function transitionsFrom(string $state): array
    {
        return match ($state) {
            'draft' => [['name' => 'submit', 'to' => 'review']],
            default => [],
        };
    }
}
