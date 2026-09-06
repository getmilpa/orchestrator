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

/** The collaborator the nodes work through — a stand-in for a model, scripted for the test. */
final class Writer
{
    /** @var list<string> */
    public array $drafted = [];

    /** @param list<Verdict> $verdicts */
    public function __construct(private array $verdicts = [])
    {
    }

    public function write(string $rubric, string $feedback): string
    {
        $this->drafted[] = $feedback;

        return 'draft ' . \count($this->drafted) . ' for ' . $rubric;
    }

    public function grade(string $draft): Verdict
    {
        return array_shift($this->verdicts) ?? Verdict::Passed;
    }
}
