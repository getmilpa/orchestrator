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

namespace Milpa\Orchestrator\Tests\Declaration;

use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Command\Operation;
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\Declaration\GraphDeclarationException;
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\Operations\DecideGraph;
use Milpa\Orchestrator\Operations\ListGraphs;
use Milpa\Orchestrator\Operations\PendingDecisions;
use Milpa\Orchestrator\Operations\ShowGraphRun;
use Milpa\Orchestrator\Operations\StartGraph;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\EssayReview;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Verdict;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Writer;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The door every surface reaches a graph through.
 *
 * These are ordinary declared operations, which is the whole point: `graph:pending` is the same
 * answer whether a terminal, an MCP client or the Desktop asks it, and a run parked in a log is
 * answerable from wherever the human happens to be.
 */
#[CoversClass(GraphRegistry::class)]
#[CoversClass(GraphRuns::class)]
#[CoversClass(ListGraphs::class)]
#[CoversClass(StartGraph::class)]
#[CoversClass(PendingDecisions::class)]
#[CoversClass(DecideGraph::class)]
#[CoversClass(ShowGraphRun::class)]
final class GraphOperationsTest extends TestCase
{
    private string $path = '';

    private ?GraphRuns $runs = null;

    private Writer $writer;

    protected function tearDown(): void
    {
        if ($this->path !== '') {
            @unlink($this->path);
        }
    }

    public function testTheCatalogueSaysWhatEachGraphNeedsToStart(): void
    {
        $listed = ($this->operation(ListGraphs::class)->handler)([]);

        self::assertSame(1, $listed['count']);
        self::assertSame('essay:review', $listed['graphs'][0]['name']);
        self::assertSame(['title', 'rubric'], $listed['graphs'][0]['required']);
        self::assertSame(['write', 'grade', 'publish', 'abandon'], $listed['graphs'][0]['nodes']);
    }

    public function testARunStartedFromOneSurfaceIsWaitingForAnyOther(): void
    {
        $started = $this->start(array_fill(0, 6, Verdict::Failed));

        self::assertSame('editor_call', $started['state']);
        self::assertNotNull($started['awaiting']);

        $pending = ($this->operation(PendingDecisions::class)->handler)([]);

        self::assertSame(1, $pending['count']);
        self::assertSame($started['instance_id'], $pending['pending'][0]['instance_id']);
        self::assertSame(
            ['publish_as_is', 'one_more_round', 'abandon'],
            $pending['pending'][0]['options'],
            'what a human is offered are the cases of the enum the routes were declared with',
        );
    }

    public function testAnsweringLetsTheRunContinueWhereThatCaseDeclared(): void
    {
        $started = $this->start(array_fill(0, 6, Verdict::Failed));

        $decided = ($this->operation(DecideGraph::class)->handler)([
            'graph' => 'essay:review',
            'instance' => $started['instance_id'],
            'decision' => 'abandon',
            'principal' => 'editor',
        ]);

        self::assertSame('abandon_done', $decided['state']);
        self::assertNull($decided['awaiting']);
        self::assertSame(0, ($this->operation(PendingDecisions::class)->handler)([])['count']);
    }

    public function testShowingARunReturnsEverythingItsNodesWrote(): void
    {
        $started = $this->start([Verdict::Passed]);

        $shown = ($this->operation(ShowGraphRun::class)->handler)([
            'graph' => 'essay:review',
            'instance' => $started['instance_id'],
        ]);

        self::assertSame('publish_done', $shown['state']);
        self::assertCount(1, $shown['context']['drafts']);
        self::assertStringStartsWith('https://example.test/', $shown['context']['url']);
    }

    public function testAnsweringARunThatIsNotWaitingIsRefused(): void
    {
        $started = $this->start([Verdict::Passed]);

        $this->expectException(GraphDeclarationException::class);
        $this->expectExceptionMessageMatches('/is not waiting for a decision/');

        ($this->operation(DecideGraph::class)->handler)([
            'graph' => 'essay:review',
            'instance' => $started['instance_id'],
            'decision' => 'abandon',
            'principal' => 'editor',
        ]);
    }

    public function testStartingChannelsThatAreNotAJsonObjectAreRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a JSON object of starting channels/');

        ($this->operation(StartGraph::class)->handler)([
            'graph' => 'essay:review',
            'inputs' => 'not json at all',
            'requester' => 'rod',
        ]);
    }

    public function testTheRegistryRefusesAClassThatIsNotAGraphAndANameWithTwoOwners(): void
    {
        $registry = new GraphRegistry();

        $this->expectException(GraphDeclarationException::class);
        $this->expectExceptionMessageMatches('/carries no #\[Graph\] attribute/');

        $registry->register(Writer::class);
    }

    public function testAskingForAGraphNobodyDeclaredSaysWhatIsDeclared(): void
    {
        $registry = (new GraphRegistry())->register(EssayReview::class);

        self::assertTrue($registry->has('essay:review'));
        self::assertFalse($registry->has('nope'));

        $this->expectException(GraphDeclarationException::class);
        $this->expectExceptionMessageMatches("/No graph named 'nope'. Declared: essay:review/");

        $registry->get('nope');
    }

    /** @param list<Verdict> $verdicts */
    private function start(array $verdicts): array
    {
        $this->writer = new Writer($verdicts);

        return ($this->operation(StartGraph::class)->handler)([
            'graph' => 'essay:review',
            'inputs' => '{"title":"Tides","rubric":"formal and metaphorical"}',
            'requester' => 'rod',
        ]);
    }

    /** @param class-string $class */
    private function operation(string $class): Operation
    {
        $this->runs ??= $this->build();
        $runs = $this->runs;

        return DeclaredOperation::from($class, static fn (string $type): object => $runs);
    }

    private function build(): GraphRuns
    {
        $this->writer ??= new Writer();
        $writer = &$this->writer;

        $registry = (new GraphRegistry(static function (string $type) use (&$writer): object {
            return $writer;
        }))->register(EssayReview::class);

        $this->path = sys_get_temp_dir() . '/graph-ops-' . uniqid('', true) . '.jsonl';

        return new GraphRuns(
            $registry,
            new FileEventStore($this->path),
            new HumanGate(new StubDecisionSurfaceFactory()),
            new EventDispatcher(new NullLogger()),
        );
    }
}
