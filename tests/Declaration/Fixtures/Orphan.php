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

/** A route whose enum nobody produces and no gate offers. */
#[Graph(name: 'lab:orphan', description: 'A decision with no decider.')]
#[Start(Plain::class)]
#[Route(when: EditorCall::Abandon, to: Second::class)]
#[Route(when: EditorCall::PublishAsIs, to: Second::class)]
#[Route(when: EditorCall::OneMoreRound, to: Second::class)]
final readonly class Orphan
{
    public function __construct(public string $title)
    {
    }
}
