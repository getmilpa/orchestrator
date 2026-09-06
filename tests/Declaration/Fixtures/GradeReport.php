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

namespace Milpa\Orchestrator\Tests\Declaration\Fixtures;

/** This class IS the structured-output contract; `Verdict` being an enum is what makes the edges. */
final readonly class GradeReport
{
    public function __construct(
        public string $feedback,
        public Verdict $verdict,
    ) {
    }
}
