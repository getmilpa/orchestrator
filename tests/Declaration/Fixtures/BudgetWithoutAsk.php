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

use Milpa\Orchestrator\Declaration\Graph;
use Milpa\Orchestrator\Declaration\Route;
use Milpa\Orchestrator\Declaration\Start;

#[Graph(name: 'lab:no-ask', description: 'A budget that simply stops.')]
#[Start(Judge::class)]
#[Route(when: Verdict::Passed, to: Plain::class)]
#[Route(when: Verdict::Failed, to: Judge::class, atMost: 2)]
final readonly class BudgetWithoutAsk
{
    public function __construct(public string $title, public ?string $verdict = null)
    {
    }
}
