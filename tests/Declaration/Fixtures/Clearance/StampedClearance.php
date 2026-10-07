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
 * The clearance whose answer «release» leads to TWO nodes: a stamp anybody may put, and then the release that needs
 * `memo:release`. So an approver without that scope answers, the stamp runs, and the run parks at the second node
 * with the answer already recorded.
 */
#[Graph(name: 'memo:stamped', description: 'Prepare and check a memo; released on a signoff, after it is stamped.')]
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
#[Route(when: Signoff::Release, to: Stamp::class)]
#[Edge(from: Stamp::class, to: Release::class)]
#[Route(when: Signoff::Shelve, to: Shelve::class)]
final readonly class StampedClearance
{
    public function __construct(
        #[Target]
        #[Because('The memo being cleared — the human names it')]
        public string $title,
        #[Because('Whether the check will find it clean')]
        public bool $clean = false,
        public string $preparedBy = '',
        public ?string $finding = null,
        public string $stampedBy = '',
        public string $releasedBy = '',
        public string $shelvedBy = '',
    ) {
    }
}
