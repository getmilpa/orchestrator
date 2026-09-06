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

use Milpa\Command\Declaration\Because;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Declaration\Reads;
use Milpa\Command\Effect\Externality;

/** `#[Reads]` is not «harmless»: the draft leaves the machine, and that is declared. */
#[Operation(name: 'essay:grade', description: 'Judge the draft against the rubric.')]
#[Reads(externality: Externality::ThirdParty)]
final readonly class Grade
{
    public function __construct(
        #[Because('The rubric to judge against')]
        public string $rubric,
        #[Because('The draft under review')]
        public string $draft,
    ) {
    }

    public function run(Writer $writer): GradeReport
    {
        $verdict = $writer->grade($this->draft);

        return new GradeReport(
            $verdict === Verdict::Passed ? 'It meets every line of the rubric.' : 'Tighten the tone and keep the metaphor.',
            $verdict,
        );
    }
}
