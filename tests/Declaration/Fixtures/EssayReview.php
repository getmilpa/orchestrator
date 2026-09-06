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
use Milpa\Command\Declaration\Target;
use Milpa\Orchestrator\Declaration\Appends;
use Milpa\Orchestrator\Declaration\Ask;
use Milpa\Orchestrator\Declaration\Edge;
use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Route;
use Milpa\Orchestrator\Declaration\Start;
use Milpa\Workflow\Enums\ApprovalPolicy;

/**
 * The whole graph: four operations and nine lines of wiring.
 *
 * Read it once — write, grade, loop on FAILED at most three times, then ask a human.
 */
#[Graph(name: 'essay:review', description: 'Draft an essay against a rubric until it passes, then publish it.')]
#[Start(Write::class)]
#[Edge(from: Write::class, to: Grade::class)]
#[Route(when: Verdict::Passed, to: Publish::class)]
#[Route(when: Verdict::Failed, to: Write::class, atMost: 3, thenAsk: EditorCall::class)]
#[Ask(
    EditorCall::class,
    of: 'editor',
    because: 'Three drafts in a row failed the rubric. A human decides whether to publish as is, pay for one more round, or close it.',
    policy: ApprovalPolicy::SINGLE,
    waivable: false,
)]
#[Route(when: EditorCall::PublishAsIs, to: Publish::class)]
#[Route(when: EditorCall::OneMoreRound, to: Write::class)]
#[Route(when: EditorCall::Abandon, to: Abandon::class)]
final readonly class EssayReview
{
    public function __construct(
        #[Target]
        #[Because('The essay being written — the human names it')]
        public string $title,
        #[Because('The rubric every draft is graded against')]
        public string $rubric,
        public string $draft = '',
        public string $feedback = '',
        #[Appends('draft')]
        public array $drafts = [],
        public ?string $verdict = null,
        public ?string $url = null,
    ) {
    }
}
