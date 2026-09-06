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

use Milpa\Orchestrator\Declaration\Ask;
use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Route;
use Milpa\Orchestrator\Declaration\Start;
use Milpa\Workflow\Enums\EvidenceType;

/** A gate that will not open on a promise: it demands evidence. */
#[Graph(name: 'lab:evidence', description: 'A gate that demands evidence.')]
#[Start(Judge::class)]
#[Route(when: Verdict::Passed, to: Plain::class)]
#[Route(when: Verdict::Failed, to: Judge::class, atMost: 1, thenAsk: EditorCall::class)]
#[Ask(EditorCall::class, of: 'editor', because: 'Show your work.', evidence: [EvidenceType::RISK_ASSESSMENT])]
#[Route(when: EditorCall::PublishAsIs, to: Plain::class)]
#[Route(when: EditorCall::OneMoreRound, to: Judge::class)]
#[Route(when: EditorCall::Abandon, to: Second::class)]
final readonly class WithEvidence
{
    public function __construct(public string $title, public ?string $verdict = null)
    {
    }
}
