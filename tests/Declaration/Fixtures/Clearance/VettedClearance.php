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
 * A clearance whose FIRST node declares a need, and whose dirty memo then asks a human: whoever resumes a run parked
 * at the vet drives it into the gate.
 */
#[Graph(name: 'memo:vetted', description: 'Vet a memo and check it: a clean one is released, a dirty one waits for a human.')]
#[Start(Vet::class)]
#[Edge(from: Vet::class, to: Check::class)]
#[Route(when: Finding::Clean, to: OpenRelease::class)]
#[Route(when: Finding::Dirty, to: Vet::class, atMost: 1, thenAsk: Signoff::class)]
#[Ask(
    Signoff::class,
    of: 'officer',
    because: 'The memo is still dirty after a second pass. A human decides whether to release it anyway or shelve it.',
    policy: ApprovalPolicy::SINGLE,
    waivable: false,
)]
#[Route(when: Signoff::Release, to: OpenRelease::class)]
#[Route(when: Signoff::Shelve, to: Shelve::class)]
final readonly class VettedClearance
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
