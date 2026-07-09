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

use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;

/**
 * Test-only {@see ToolRegistryInterface}: records every registered tool's input schema by name
 * instead of wiring it into a real registry pipeline — this package's own tests only need to
 * assert on the SCHEMA {@see \Milpa\ToolRuntime\ToolScanner} generates, not exercise policy,
 * rate-limiting, or execution.
 */
final class RecordingToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    public function register(
        string $name,
        string $description,
        array $inputSchema,
        callable $callback,
        ?ToolOptions $options = null,
    ): void {
        $this->schemas[$name] = $inputSchema;
    }

    /**
     * The input schema registered under `$name`, or `null` if nothing was registered under it.
     *
     * @return array<string, mixed>|null
     */
    public function schemaFor(string $name): ?array
    {
        return $this->schemas[$name] ?? null;
    }
}
