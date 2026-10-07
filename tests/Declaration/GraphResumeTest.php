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

use Milpa\Command\Declaration\DeclarationException;
use Milpa\Command\Declaration\DeclaredOperation;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationRunner;
use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\DeclaredGraph;
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\Operations\GraphOperations;
use Milpa\Orchestrator\Operations\ResumeGraph;
use Milpa\Orchestrator\Operations\StartGraph;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\JammedClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\MemoClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\OpenClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\StampedClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\VettedClearance;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * A run that parked because its caller could not run the next node is carried on by a caller who can.
 *
 * A refused node used to be the end of a run: it stayed in the log at that node, no gate was waiting, and no
 * operation moved it. `graph:resume` is one more invocation that reaches that node — so the node runs AS WHOEVER
 * RESUMES, judged against what that call holds, exactly as it is judged for whoever starts a run or answers a gate.
 * These tests drive it through the same {@see OperationRunner} every surface calls, and say what resuming is not: an
 * answer to a gate, a retry of a node that broke, or a way to approve one's own work.
 */
#[CoversClass(GraphOperations::class)]
#[CoversClass(ResumeGraph::class)]
#[CoversClass(StartGraph::class)]
#[CoversClass(GraphRuns::class)]
#[CoversClass(Caller::class)]
final class GraphResumeTest extends TestCase
{
    private string $path = '';

    private GraphRuns $runs;

    /** @var array<string, Operation> */
    private array $operations = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/graph-resume-' . uniqid('', true) . '.jsonl';
        $this->runs = new GraphRuns(
            (new GraphRegistry())
                ->register(MemoClearance::class)
                ->register(OpenClearance::class)
                ->register(StampedClearance::class)
                ->register(VettedClearance::class)
                ->register(JammedClearance::class),
            new FileEventStore($this->path),
            new HumanGate(new StubDecisionSurfaceFactory()),
            new EventDispatcher(new NullLogger()),
        );

        $services = [GraphRuns::class => $this->runs];
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id): mixed => $services[$id] ?? throw new \RuntimeException("no {$id}"));
        $this->container = $container;

        foreach ((new GraphOperations($container))->operations() as $operation) {
            $this->operations[$operation->name] = $operation;
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testARunParkedByARefusedNodeIsCarriedOnByACallerWhoMayRunIt(): void
    {
        // The clerk may start graphs and may not release memos: the run parks at the node that needs it.
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        self::assertSame([false, 'release', null], [$parked['ok'] ?? true, $parked['state'] ?? null, $parked['awaiting'] ?? null], (string) json_encode($parked));

        $resumed = $this->resume('memo:clearance', $parked, self::verified('actor:officer'), self::holding('officer', 'graph:run', 'memo:release'));

        self::assertSame('release_done', $resumed['state'] ?? null, (string) json_encode($resumed));
        self::assertArrayNotHasKey('ok', $resumed, 'a run that went on says where it stands, as a start does');
        self::assertSame('actor:clerk', $this->channel('memo:clearance', $parked, 'preparedBy'), 'what ran before the refusal ran as whoever started the run');
        self::assertSame('actor:officer', $this->channel('memo:clearance', $parked, 'releasedBy'), 'the node that was refused ran as whoever resumed it');
    }

    public function testTheNodeIsJudgedAgainstWhatTheCallThatResumesHolds(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));

        // The intern may resume runs. Nothing says the intern may release memos either.
        $refused = $this->resume('memo:clearance', $parked, self::verified('actor:intern'), self::holding('intern', 'graph:run'));

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertStringContainsString('memo:release, which actor:intern does not hold', (string) ($refused['error'] ?? ''));
        self::assertSame('release', $refused['state'] ?? null, 'the run is still parked AT the node');
        self::assertSame(['node' => 'release', 'operation' => 'memo:release', 'needs' => ['memo:release']], array_diff_key($refused['refused'] ?? [], ['by' => 1]));
        self::assertNull($this->channel('memo:clearance', $parked, 'releasedBy'));
        self::assertSame(['clerk', 'intern'], $this->whoWasRefused(), 'each refusal is in the log under the name of who was refused');

        // …and being refused spent nothing: whoever may run the node still carries the run on.
        $resumed = $this->resume('memo:clearance', $parked, self::verified('actor:officer'), self::holding('officer', 'graph:run', 'memo:release'));
        self::assertSame('release_done', $resumed['state'] ?? null, (string) json_encode($resumed));
    }

    public function testAResumeThatCarriesNoAuthorityRunsNothing(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));

        $refused = $this->resume('memo:clearance', $parked, self::verified('key:ROD'), null);

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertStringContainsString('carries no authority', (string) ($refused['error'] ?? ''));
        self::assertNull($this->channel('memo:clearance', $parked, 'releasedBy'));
    }

    public function testAResumeDoesNotBorrowTheScopesOfWhoeverStartedTheRunOrAnsweredItsGate(): void
    {
        // The starter held memo:release. The clerk who answered did not — so the stamp ran and the release did not.
        $started = $this->start('memo:stamped', ['title' => 'Q3'], self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run', 'memo:release'));
        $answered = $this->decide('memo:stamped', $started, 'release', self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));
        self::assertSame([false, 'release'], [$answered['ok'] ?? true, $answered['state'] ?? null], (string) json_encode($answered));

        // Neither of them left anything at the node for the next caller to spend.
        $refused = $this->resume('memo:stamped', $started, self::verified('actor:intern'), self::holding('intern', 'graph:run'));

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertNull($this->channel('memo:stamped', $started, 'releasedBy'));
        self::assertSame(['clerk', 'intern'], $this->whoWasRefused());
    }

    public function testTheAnswerAlreadyRecordedStaysWhenTheNodeRefusedWasTheSecondOneAfterTheGate(): void
    {
        $started = $this->start('memo:stamped', ['title' => 'Q3'], self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run'));
        self::assertSame('signoff', $started['state'] ?? null, (string) json_encode($started));

        // «release» leads to the stamp, which needs nothing, so the clerk's answer is taken and the stamp runs as
        // the clerk. The node after it needs memo:release: the run parks there, with the gate already answered.
        $this->decide('memo:stamped', $started, 'release', self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));
        $before = $this->events();

        // Whoever resumes sends what they like; `graph:resume` takes the graph and the run, and nothing else.
        $resumed = $this->resume(
            'memo:stamped',
            $started,
            self::verified('actor:officer'),
            self::holding('officer', 'graph:run', 'memo:release'),
            ['decision' => 'shelve', 'inputs' => '{"title":"forged"}', 'title' => 'forged', 'requester' => 'actor:officer'],
        );

        self::assertSame('release_done', $resumed['state'] ?? null, 'the run went on along the answer that was given, not the one sent now');
        self::assertSame(
            [['release', 'actor:clerk']],
            array_values(array_map(
                static fn (array $event): array => [$event['type'], $event['payload']['by']],
                array_filter($this->events(), static fn (array $event): bool => \is_string($event['payload']['by'] ?? null)),
            )),
            'the gate was answered once, by the clerk',
        );
        self::assertSame($before, \array_slice($this->events(), 0, \count($before)), 'nothing that was in the log was rewritten');
        self::assertSame('actor:clerk', $this->channel('memo:stamped', $started, 'stampedBy'), 'the node that ran after the answer is not run again');
        self::assertSame(1, \count(array_keys(array_column($this->trail('memo:stamped', $started), 'node'), 'stamp', true)));
        self::assertSame('actor:officer', $this->channel('memo:stamped', $started, 'releasedBy'));
        self::assertSame('Q3', $this->channel('memo:stamped', $started, 'title'));
        self::assertNull($this->channel('memo:stamped', $started, 'shelvedBy'));
    }

    public function testARunWaitingForADecisionIsNotResumed(): void
    {
        $started = $this->start('memo:clearance', ['title' => 'Q3'], self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run'));
        self::assertSame('signoff_gate', $started['awaiting'] ?? null, (string) json_encode($started));
        $before = $this->events();

        $refused = $this->resume('memo:clearance', $started, self::verified('actor:rod'), self::holding('rod', '*'));
        self::assertFalse($refused['ok'] ?? true, 'a run that waits for a decision was resumed');
        self::assertStringContainsString('is waiting for a decision: answer it with graph:decide', (string) ($refused['error'] ?? ''));

        self::assertSame($before, $this->events(), 'nothing was written');
        self::assertCount(1, $this->runs->pending());
    }

    public function testResumingIsNotAWayToGiveTheAnswerThatWasNotRecorded(): void
    {
        $started = $this->start('memo:clearance', ['title' => 'Q3'], self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run'));

        // The clerk's «release» leads straight to a node the clerk may not run, so it was not recorded — and the
        // last thing the log says of this run is that refusal.
        $unanswered = $this->decide('memo:clearance', $started, 'release', self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));
        self::assertSame([false, 'signoff_gate', 'release'], [$unanswered['ok'] ?? true, $unanswered['awaiting'] ?? null, $unanswered['refused']['answer'] ?? null]);
        $before = $this->events();

        // Somebody who may release memos, and may not decide: resuming is not how the clerk's answer gets taken.
        $refused = $this->resume('memo:clearance', $started, self::verified('actor:officer'), self::holding('officer', 'graph:run', 'memo:release'));
        self::assertFalse($refused['ok'] ?? true, 'a run that waits for a decision was resumed');
        self::assertStringContainsString('is waiting for a decision: answer it with graph:decide', (string) ($refused['error'] ?? ''));

        self::assertSame($before, $this->events(), 'nothing was written');
        self::assertNull($this->channel('memo:clearance', $started, 'releasedBy'));
        self::assertSame('signoff_gate', $this->runs->pending()[0]['gate_id'] ?? null, 'the gate is still waiting for an answer');
    }

    public function testAFinishedRunIsNotResumed(): void
    {
        $finished = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:rod'), self::holding('rod', '*'));
        self::assertSame('release_done', $finished['state'] ?? null, (string) json_encode($finished));
        $before = $this->events();

        $refused = $this->resume('memo:clearance', $finished, self::verified('actor:rod'), self::holding('rod', '*'));
        self::assertFalse($refused['ok'] ?? true, 'a finished run was resumed');
        self::assertStringContainsString('has finished', (string) ($refused['error'] ?? ''));

        self::assertSame($before, $this->events(), 'nothing was written');
    }

    public function testARunWhoseNodeBrokeIsNotResumedBecauseItWasNeverRefused(): void
    {
        // The printer may print: the node is reached, and breaks halfway. That is not a refusal.
        $instance = $this->startThatBreaks(self::verified('actor:printer'), self::holding('printer', 'graph:run', 'memo:print'));
        self::assertSame('jam', $this->runs->show('memo:jammed', $instance)['state']);
        $before = $this->events();

        $refused = $this->resume('memo:jammed', ['instance_id' => $instance], self::verified('actor:printer'), self::holding('printer', '*'));
        self::assertFalse($refused['ok'] ?? true, 'a run that stopped on a node that broke was resumed — a node that may have done half its work, run again');
        self::assertStringContainsString('is not parked by a refused node', (string) ($refused['error'] ?? ''));

        self::assertSame($before, $this->events(), 'nothing was written');
    }

    public function testAResumeIsSpentOnceItIsTaken(): void
    {
        $parked = $this->start('memo:jammed', ['title' => 'Q3'], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        self::assertSame([false, 'jam'], [$parked['ok'] ?? true, $parked['state'] ?? null], (string) json_encode($parked));

        // The printer may run the node, so the resume is taken — and the node breaks halfway.
        try {
            $this->resume('memo:jammed', $parked, self::verified('actor:printer'), self::holding('printer', 'graph:run', 'memo:print'));
            self::fail('the node did not break');
        } catch (\RuntimeException $broke) {
            self::assertSame('the press jammed', $broke->getMessage());
        }

        // The run is no longer parked by a refusal: it is a run whose node broke, and resuming is not a retry.
        $refused = $this->resume('memo:jammed', $parked, self::verified('actor:printer'), self::holding('printer', 'graph:run', 'memo:print'));
        self::assertFalse($refused['ok'] ?? true, 'a node that broke after a resume was run again');
        self::assertStringContainsString('is not parked by a refused node', (string) ($refused['error'] ?? ''));

        self::assertSame(
            [['ran', 'prepare', 'actor:clerk'], ['refused', 'jam', 'actor:clerk'], ['resumed', 'jam', 'actor:printer']],
            $this->steps('memo:jammed', $parked),
        );
    }

    public function testARunThatWasNeverRefusedIsNotResumedWhateverItWasStartedWith(): void
    {
        // A host driving the engine itself starts a run with whatever payload it likes — here, one that claims the
        // first node was refused. A refusal is an event the engine appends, never a line of how a run was started:
        // not when the engine's own start follows it, and not when that claim is the last thing in the log.
        $graph = DeclaredGraph::from(VettedClearance::class);
        $store = new FileEventStore($this->path);
        $claim = ['node' => 'vet', 'operation' => 'memo:vet', 'needs' => ['memo:vet'], 'by' => ['actor' => 'actor:clerk']];
        $payload = ['title' => 'Q3', '_definition' => 'memo:vetted', '_refused' => $claim];

        $started = ProcessInstance::start($store, $graph->definition, $payload)->instanceId;
        $store->append(new Event('written-by-hand', 'ProcessStarted', $payload, $store->nextSeq()));

        foreach ([$started, 'written-by-hand'] as $instance) {
            self::assertSame('vet', $this->runs->show('memo:vetted', $instance)['state']);

            $refused = $this->resume('memo:vetted', ['instance_id' => $instance], self::verified('actor:rod'), self::holding('rod', '*'));
            self::assertFalse($refused['ok'] ?? true, 'a run nobody was refused was resumed');
            self::assertStringContainsString('is not parked by a refused node', (string) ($refused['error'] ?? ''));

            self::assertSame([], $this->trail('memo:vetted', ['instance_id' => $instance]));
        }
    }

    public function testWhoeverTriedToResumeAndCouldNotResumedNothing(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));

        $this->resume('memo:clearance', $parked, self::verified('actor:intern'), self::holding('intern', 'graph:run'));

        self::assertSame(
            [['ran', 'prepare', 'actor:clerk'], ['ran', 'check', 'actor:clerk'], ['refused', 'release', 'actor:clerk'], ['refused', 'release', 'actor:intern']],
            $this->steps('memo:clearance', $parked),
            'the log says the intern was refused, and does not say the intern resumed anything',
        );
        self::assertNotContains(GraphRuns::RUN_RESUMED, array_column($this->events(), 'type'));
    }

    public function testTheTrailSaysWhoWasRefusedWhoResumedAndWhoRan(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        $this->resume('memo:clearance', $parked, self::verified('actor:officer'), self::holding('officer', 'graph:run', 'memo:release'));

        self::assertSame(
            [
                ['ran', 'prepare', 'actor:clerk'],
                ['ran', 'check', 'actor:clerk'],
                ['refused', 'release', 'actor:clerk'],
                ['resumed', 'release', 'actor:officer'],
                ['ran', 'release', 'actor:officer'],
            ],
            $this->steps('memo:clearance', $parked),
        );

        $resumed = array_values(array_filter($this->events(), static fn (array $event): bool => $event['type'] === GraphRuns::RUN_RESUMED));
        self::assertSame(
            [[GraphRuns::RESUMED => ['node' => 'release', 'operation' => 'memo:release', 'by' => ['actor' => 'actor:officer', 'verified' => true, 'channel' => 'web', 'principal' => 'officer', 'authorization' => 'test']]]],
            array_column($resumed, 'payload'),
            'the event says at which node and who — never what that caller holds',
        );
    }

    public function testWhoStartedARunStillCannotApproveItAfterResumingItThemselves(): void
    {
        // agent-7 could not vet when it started the run. Later it can — and resumes its own run into the gate.
        $parked = $this->start('memo:vetted', ['title' => 'Q3'], self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run'));
        self::assertSame([false, 'vet'], [$parked['ok'] ?? true, $parked['state'] ?? null], (string) json_encode($parked));

        $resumed = $this->resume('memo:vetted', $parked, self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run', 'memo:vet'));
        self::assertSame('signoff_gate', $resumed['awaiting'] ?? null, (string) json_encode($resumed));

        $own = $this->decide('memo:vetted', $parked, 'release', self::verified('actor:agent-7'), self::holding('agent-7', '*'));

        self::assertFalse($own['ok'] ?? true, (string) json_encode($own));
        self::assertStringContainsString('actor:agent-7 opened this gate, so it cannot approve it', (string) ($own['error'] ?? ''));
        self::assertNull($this->channel('memo:vetted', $parked, 'releasedBy'));
    }

    public function testWhoResumesARunDoesNotBecomeWhoAskedForIt(): void
    {
        $parked = $this->start('memo:vetted', ['title' => 'Q3'], self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run'));

        $resumed = $this->resume('memo:vetted', $parked, self::verified('actor:officer'), self::holding('officer', 'graph:run', 'memo:vet'));
        self::assertSame('signoff_gate', $resumed['awaiting'] ?? null, (string) json_encode($resumed));
        self::assertSame('actor:agent-7', $this->runs->pending()[0]['requester'] ?? null, 'the run is still the run of whoever started it');

        // The one who asked is still the one who may not approve — somebody else resuming the run changed nothing.
        $own = $this->decide('memo:vetted', $parked, 'release', self::verified('actor:agent-7'), self::holding('agent-7', '*'));
        self::assertStringContainsString('actor:agent-7 opened this gate, so it cannot approve it', (string) ($own['error'] ?? ''));

        // And whoever resumed it is not who asked: they answer the gate as anybody but the starter would.
        $answered = $this->decide('memo:vetted', $parked, 'release', self::verified('actor:officer'), self::holding('officer', 'graph:decide'));
        self::assertSame('release_done', $answered['state'] ?? null, (string) json_encode($answered));
        self::assertSame('actor:officer', $this->channel('memo:vetted', $parked, 'releasedBy'));
    }

    public function testARunWithNobodyToAskForItTakesWhoeverResumesItAsTheOneWhoAsked(): void
    {
        // Started below the door, so its log names no requester — and parked the way the engine parks a run.
        $graph = DeclaredGraph::from(VettedClearance::class);
        $store = new FileEventStore($this->path);
        $instance = ProcessInstance::start($store, $graph->definition, ['title' => 'Q3', '_definition' => 'memo:vetted']);
        $store->append(new Event($instance->instanceId, GraphRuns::NODE_REFUSED, [GraphRuns::REFUSED => ['node' => 'vet', 'operation' => 'memo:vet', 'needs' => ['memo:vet'], 'by' => ['actor' => null]]], $store->nextSeq()));
        $run = ['instance_id' => $instance->instanceId];

        $resumed = $this->resume('memo:vetted', $run, self::verified('actor:officer'), self::holding('officer', 'graph:run', 'memo:vet'));
        self::assertSame('signoff_gate', $resumed['awaiting'] ?? null, (string) json_encode($resumed));

        // A gate nobody is named for would be one anybody could approve — including whoever drove the run into it.
        $own = $this->decide('memo:vetted', $run, 'release', self::verified('actor:officer'), self::holding('officer', '*'));
        self::assertStringContainsString('actor:officer opened this gate, so it cannot approve it', (string) ($own['error'] ?? ''));

        // …and a call nobody was verified for is named by its door, as it is when it starts a run.
        $second = ProcessInstance::start($store, $graph->definition, ['title' => 'Q4', '_definition' => 'memo:vetted']);
        $store->append(new Event($second->instanceId, GraphRuns::NODE_REFUSED, [GraphRuns::REFUSED => ['node' => 'vet', 'operation' => 'memo:vet', 'needs' => ['memo:vet'], 'by' => ['actor' => null]]], $store->nextSeq()));
        $this->resume('memo:vetted', ['instance_id' => $second->instanceId], new InvocationContext(actor: 'actor:claims-to-be-rod', verified: false, channel: 'mcp'), self::holding('token-9', 'graph:run', 'memo:vet'));

        self::assertSame(
            ['actor:officer', 'unverified:mcp'],
            array_values(array_map(
                static fn (array $event): string => (string) $event['payload']['requester'],
                array_filter($this->events(), static fn (array $event): bool => $event['type'] === 'GateOpened'),
            )),
        );
    }

    public function testARunIsResumedOnlyAsARunOfItsOwnGraph(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        $before = $this->events();

        // open:clearance has the same shape and a release anybody may run. Whose nodes are judged is the run's own.
        $refused = $this->resume('open:clearance', $parked, self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        self::assertFalse($refused['ok'] ?? true, 'a run was resumed under the name of another graph');
        self::assertStringContainsString("is not a run of 'open:clearance'", (string) ($refused['error'] ?? ''));

        self::assertSame($before, $this->events(), 'nothing was written');
    }

    public function testOverHttpTheScopesOfThePasskeySessionDecideWhetherTheNodeRuns(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        $body = ['graph' => 'memo:clearance', 'instance' => (string) $parked['instance_id']];

        $refused = $this->http('graph:resume', $body, 'intern', ['graph:run']);
        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertSame('release', $refused['state'] ?? null);

        $resumed = $this->http('graph:resume', $body, 'officer', ['graph:run', 'memo:release']);
        self::assertSame('release_done', $resumed['state'] ?? null, (string) json_encode($resumed));
        self::assertSame(['clerk', 'intern'], $this->whoWasRefused());
    }

    public function testOverMcpTheNodeRunsUnderTheAuthorityOfTheToolCall(): void
    {
        $tools = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll(array_values($this->operations), $tools, $this->container);
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));
        $call = ['graph' => 'memo:clearance', 'instance' => (string) $parked['instance_id']];

        $scoped = $tools->call('graph_resume', $call, ToolContext::mcp('req-1', 'agent-7', ['graph:run']));
        self::assertSame(['clerk', 'agent-7'], $this->whoWasRefused(), (string) json_encode($scoped->toArray()));

        $holder = $tools->call('graph_resume', $call, ToolContext::mcp('req-2', 'agent-9', ['graph:run', 'memo:release']));
        self::assertTrue($holder->success, (string) json_encode($holder->toArray()));
        self::assertSame('release_done', $holder->data['state'] ?? null);

        $trail = $this->trail('memo:clearance', $parked);
        self::assertSame(['actor' => null, 'verified' => false, 'channel' => 'mcp', 'principal' => 'agent-9'], $trail[\count($trail) - 1]['by']);
    }

    public function testResumingIsDeclaredAsStartingIs(): void
    {
        $declared = get_object_vars(DeclaredOperation::from(ResumeGraph::class, fn (string $type): object => $this->runs));
        $served = get_object_vars($this->operations['graph:resume']);

        self::assertSame(['graph:run'], $served['scopes'], 'resuming runs nodes: it asks for what starting asks for');
        self::assertEquals($this->operations['graph:start']->effects, $this->operations['graph:resume']->effects, 'and it changes what starting changes');
        self::assertSame(['graph', 'instance'], array_keys($served['inputSchema']['properties'] ?? []), 'it takes which run — nothing to decide, nothing to seed');
        self::assertSame(['graph', 'instance'], $served['inputSchema']['required'] ?? null);

        // What a surface reads is still derived from the declared class; the door changed its handler and nothing else.
        self::assertNotSame($declared['handler'], $served['handler']);
        unset($declared['handler'], $served['handler']);
        self::assertEquals($declared, $served);
    }

    public function testCalledAsADeclaredOperationItIsHandedNoAuthoritySoAScopedNodeDoesNotRun(): void
    {
        $parked = $this->start('memo:clearance', ['title' => 'Q3', 'clean' => true], self::verified('actor:clerk'), self::holding('clerk', 'graph:run'));

        // The declared handler receives who is calling and never what they hold — so through it the caller holds
        // nothing, as with graph:start and graph:decide.
        $refused = (DeclaredOperation::from(ResumeGraph::class, fn (string $type): object => $this->runs)->handler)(
            ['graph' => 'memo:clearance', 'instance' => (string) $parked['instance_id']],
            self::verified('actor:officer'),
        );

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertStringContainsString('carries no authority', (string) ($refused['error'] ?? ''));
    }

    public function testAMissingInputIsRefusedByTheDoorTheWayTheDeclaredOperationRefusesIt(): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage("missing required input 'instance'");

        (new OperationRunner($this->container))->run($this->operations['graph:resume'], ['graph' => 'memo:clearance'], 'cli', self::verified('actor:rod'), self::holding('rod', '*'));
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    private function start(string $graph, array $inputs, ?InvocationContext $context, ?ToolContext $authority): array
    {
        /** @var array<string, mixed> $result */
        $result = (new OperationRunner($this->container))->run(
            $this->operations['graph:start'],
            ['graph' => $graph, 'inputs' => (string) json_encode($inputs)],
            'cli',
            $context,
            $authority,
        );

        return $result;
    }

    /** Starts memo:jammed for a caller who may run the node that breaks, and returns the run it left behind. */
    private function startThatBreaks(InvocationContext $context, ToolContext $authority): string
    {
        try {
            $this->start('memo:jammed', ['title' => 'Q3'], $context, $authority);
            self::fail('the node did not break');
        } catch (\RuntimeException $broke) {
            self::assertSame('the press jammed', $broke->getMessage());
        }

        return (string) $this->events()[0]['stream_id'];
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $extra what a caller sends beyond what the operation declares
     *
     * @return array<string, mixed>
     */
    private function resume(string $graph, array $run, ?InvocationContext $context, ?ToolContext $authority, array $extra = []): array
    {
        /** @var array<string, mixed> $result */
        $result = (new OperationRunner($this->container))->run(
            $this->operations['graph:resume'],
            ['graph' => $graph, 'instance' => (string) $run['instance_id']] + $extra,
            'cli',
            $context,
            $authority,
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private function decide(string $graph, array $run, string $decision, ?InvocationContext $context, ?ToolContext $authority): array
    {
        /** @var array<string, mixed> $result */
        $result = (new OperationRunner($this->container))->run(
            $this->operations['graph:decide'],
            ['graph' => $graph, 'instance' => (string) $run['instance_id'], 'decision' => $decision],
            'http',
            $context,
            $authority,
        );

        return $result;
    }

    /**
     * One call through the HTTP projector as the panel's door makes it — the passkey session in `milpa.auth`, with
     * the scopes the host verified for it — walking the confirm ceremony a mutating operation demands.
     *
     * @param array<string, mixed> $body
     * @param list<string>         $scopes
     *
     * @return array<string, mixed>
     */
    private function http(string $operation, array $body, string $actor, array $scopes): array
    {
        $policy = new class () implements OperationHttpPolicy {
            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };
        $psr17 = new Psr17Factory();
        $projector = new HttpProjector(array_values($this->operations), $this->container, $psr17, $psr17, policy: $policy);
        $route = null;
        foreach ($projector->routes() as $candidate) {
            if ($candidate->name === $operation) {
                $route = $candidate;
            }
        }
        self::assertNotNull($route);

        $auth = new class ($actor, $scopes) {
            public object $actor;

            /** @param list<string> $scopes */
            public function __construct(string $id, array $scopes)
            {
                $this->actor = (object) ['id' => $id, 'scopes' => $scopes];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        $request = (new ServerRequest('POST', '/'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route))
            ->withAttribute('milpa.auth', $auth)
            ->withBody($psr17->createStream((string) json_encode($body)));

        $response = $projector->handle($request);
        if ($response->getStatusCode() === 428) {
            $token = (string) (json_decode((string) $response->getBody(), true)['confirm_token'] ?? '');
            $response = $projector->handle($request->withHeader('Confirm-Token', $token));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The log as it is on disk, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $events = [];
        foreach (file($this->path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (\is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * The principals the log says were refused a node, in order.
     *
     * @return list<string|null>
     */
    private function whoWasRefused(): array
    {
        $who = [];
        foreach ($this->events() as $event) {
            if ($event['type'] === GraphRuns::NODE_REFUSED) {
                $who[] = $event['payload'][GraphRuns::REFUSED]['by']['principal'] ?? null;
            }
        }

        return $who;
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return list<array<string, mixed>>
     */
    private function trail(string $graph, array $run): array
    {
        return $this->runs->show($graph, (string) $run['instance_id'])['trail'];
    }

    /**
     * The trail as outcome, node and actor — what a reader follows a run by.
     *
     * @param array<string, mixed> $run
     *
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private function steps(string $graph, array $run): array
    {
        return array_map(
            static fn (array $step): array => [$step['outcome'], $step['node'], $step['by']['actor'] ?? null],
            $this->trail($graph, $run),
        );
    }

    /** @param array<string, mixed> $run */
    private function channel(string $graph, array $run, string $channel): mixed
    {
        return $this->runs->show($graph, (string) $run['instance_id'])['context'][$channel] ?? null;
    }

    /** Who the surface authenticated, as it hands it to a handler. */
    private static function verified(string $actor): InvocationContext
    {
        return new InvocationContext(actor: $actor, verified: true, channel: 'web', authorizationId: 'test');
    }

    /** The authority a surface verified for a caller: exactly these scopes, nothing implied. */
    private static function holding(string $principal, string ...$scopes): ToolContext
    {
        return ToolContext::web($principal, array_values($scopes));
    }
}
