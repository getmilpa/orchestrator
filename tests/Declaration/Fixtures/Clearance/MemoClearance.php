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

namespace Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance;

use Milpa\Command\Declaration\Because;
use Milpa\Command\Declaration\Target;
use Milpa\Orchestrator\Declaration\Ask;
use Milpa\Orchestrator\Declaration\Edge;
use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Route;
use Milpa\Orchestrator\Declaration\Start;
use Milpa\Workflow\Enums\ApprovalPolicy;

/**
 * A graph with a scoped node on BOTH sides of a human gate, whose nodes say who ran them.
 *
 * A clean memo is released without asking anyone; a dirty one gets a second pass and then waits for
 * an officer, who releases it anyway or shelves it. `memo:release` is the only node that declares a
 * scope, so the same graph shows a node refused before the gate and one refused, or run, after it.
 */
#[Graph(name: 'memo:clearance', description: 'Prepare a memo and check it: a clean one is released, a dirty one waits for a human.')]
#[Start(Prepare::class)]
#[Edge(from: Prepare::class, to: Check::class)]
#[Route(when: Finding::Clean, to: Release::class)]
#[Route(when: Finding::Dirty, to: Prepare::class, atMost: 1, thenAsk: Signoff::class)]
#[Ask(
    Signoff::class,
    of: 'officer',
    because: 'The memo is still dirty after a second pass. A human decides whether to release it anyway or shelve it.',
    policy: ApprovalPolicy::SINGLE,
    waivable: false,
)]
#[Route(when: Signoff::Release, to: Release::class)]
#[Route(when: Signoff::Shelve, to: Shelve::class)]
final readonly class MemoClearance
{
    public function __construct(
        #[Target]
        #[Because('The memo being cleared — the human names it')]
        public string $title,
        #[Because('Whether the check will find it clean')]
        public bool $clean = false,
        public string $preparedBy = '',
        public ?string $finding = null,
        public string $releasedBy = '',
        public string $shelvedBy = '',
    ) {
    }
}
