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
use Milpa\Command\Declaration\Mutates;
use Milpa\Command\Declaration\Operation;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;

#[Operation(name: 'essay:write', description: 'Write the next draft against the rubric.')]
#[Mutates(
    Mutation::Persistent,
    Externality::ThirdParty,
    Reversibility::Guaranteed,
    subject: Subject::Data,
    rollback: 'essay:drop-draft',
)]
final readonly class Write
{
    public function __construct(
        #[Because('The rubric the draft must satisfy')]
        public string $rubric,
        #[Because('The grader feedback on the previous draft; empty on the first round')]
        public string $feedback = '',
    ) {
    }

    public function run(Writer $writer): Draft
    {
        return new Draft($writer->write($this->rubric, $this->feedback));
    }
}
