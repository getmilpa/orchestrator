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

use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\OperationRunner;
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\Operations\GraphOperations;
use Milpa\Orchestrator\Operations\PendingDecisions;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\MemoClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\StampedClearance;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A waiting decision says where each of its options leads — and, to whoever is looking, which ones they may take.
 *
 * A gate offered its options as bare names. Whether the person looking could take one was found out by pressing it:
 * an answer that leads to a node its approver may not run is not recorded, and whoever started a run may not approve
 * it at all — both said only after the fact (greenhouse evidence/1122, decisions/0584).
 *
 * `pending()` now says it beforehand, and it is THE ENGINE that says it, with the rule it judges an answer by
 * ({@see Caller::refusalOf()}) — a surface paints what it is told and judges nothing. What it says is information:
 * `graph:decide` still judges every answer the same way, whatever a surface showed.
 */
#[CoversClass(GraphRuns::class)]
#[CoversClass(PendingDecisions::class)]
#[CoversClass(Caller::class)]
final class PendingSaysWhoMayTakeEachOptionTest extends TestCase
{
    private string $path = '';

    private GraphRuns $runs;

    /** @var array<string, Operation> */
    private array $operations = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/graph-pending-who-may-' . uniqid('', true) . '.jsonl';
        $this->runs = new GraphRuns(
            (new GraphRegistry())->register(MemoClearance::class)->register(StampedClearance::class),
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

    public function testAWaitingDecisionSaysWhereEachOptionLeadsAndWhatThatNodeNeeds(): void
    {
        $this->waiting('memo:clearance');

        $row = $this->runs->pending()[0];

        self::assertSame(['release', 'shelve'], $row['options'], 'the options are what they were');
        self::assertSame(
            [
                ['option' => 'release', 'leads_to' => 'release', 'operation' => 'memo:release', 'needs' => ['memo:release']],
                ['option' => 'shelve', 'leads_to' => 'shelve', 'operation' => 'memo:shelve', 'needs' => []],
            ],
            $row['choices'],
            'and each one says the node it leads to and what that node declares it needs',
        );
        self::assertArrayNotHasKey('viewer', $row, 'with nobody looking, nobody is judged');
    }

    public function testOnlyTheFirstNodeAnOptionLeadsToIsSaid(): void
    {
        // «release» leads to the stamp, which needs nothing; the release after it needs memo:release. What comes
        // after the first node is decided by what that node returns — so only the first one is judged, here as when
        // the answer is given.
        $this->waiting('memo:stamped');

        $choices = array_column($this->runs->pending(self::viewer('actor:clerk', 'clerk', 'graph:decide'))[0]['choices'], null, 'option');

        self::assertSame(['stamp', 'memo:stamp', []], [$choices['release']['leads_to'], $choices['release']['operation'], $choices['release']['needs']]);
        self::assertTrue($choices['release']['may']);
    }

    public function testToWhoeverIsLookingItSaysWhichOptionsTheyMayTake(): void
    {
        $this->waiting('memo:clearance');

        $row = $this->runs->pending(self::viewer('actor:clerk', 'clerk', 'graph:decide'))[0];
        $choices = array_column($row['choices'], null, 'option');

        self::assertSame(['is_requester' => false, 'verified' => true], $row['viewer']);
        self::assertFalse($choices['release']['may'], 'the clerk does not hold what the node «release» leads to needs');
        self::assertSame(
            "it leads to the node 'release' (memo:release), and it needs the scope memo:release, which actor:clerk does not hold",
            $choices['release']['why_not'],
        );
        self::assertSame('needs', $choices['release']['because'], 'and the rule that refused it is named, for a surface to word');
        self::assertTrue($choices['shelve']['may'], 'shelving needs nothing');
        self::assertNull($choices['shelve']['why_not']);
        self::assertNull($choices['shelve']['because']);

        $officer = array_column($this->runs->pending(self::viewer('actor:officer', 'officer', 'graph:decide', 'memo:release'))[0]['choices'], 'may', 'option');
        self::assertSame(['release' => true, 'shelve' => true], $officer);

        $owner = array_column($this->runs->pending(self::viewer('actor:rod', 'rod', '*'))[0]['choices'], 'may', 'option');
        self::assertSame(['release' => true, 'shelve' => true], $owner);
    }

    public function testWhoeverStartedTheRunMayTakeNoneOfThem(): void
    {
        $this->waiting('memo:clearance');

        // The one who asked holds everything — and it is still their own work.
        $row = $this->runs->pending(self::viewer('actor:agent-7', 'agent-7', '*'))[0];

        self::assertTrue($row['viewer']['is_requester']);
        foreach ($row['choices'] as $choice) {
            self::assertFalse($choice['may'], $choice['option']);
            self::assertSame('actor:agent-7 opened this gate, so it cannot approve it: the work and its approval need two different people', $choice['why_not']);
            self::assertSame('requester', $choice['because']);
        }
    }

    public function testACallerNobodyVerifiedMayTakeNoneOfThem(): void
    {
        $this->waiting('memo:clearance');

        foreach ([
            new Caller(new InvocationContext(actor: 'actor:claims-to-be-rod', verified: false, channel: 'mcp'), ToolContext::mcp('req-1', 'token-9', ['*'])),
            new Caller(null, ToolContext::stdio('req-2')),
        ] as $unverified) {
            $row = $this->runs->pending($unverified)[0];

            self::assertSame(['is_requester' => false, 'verified' => false], $row['viewer']);
            foreach ($row['choices'] as $choice) {
                self::assertFalse($choice['may'], $choice['option']);
                self::assertStringContainsString('a gate is answered by a verified actor', (string) $choice['why_not']);
                self::assertSame('unverified', $choice['because']);
            }
        }
    }

    public function testWhatTheViewerHoldsIsNeverSaidBack(): void
    {
        $this->waiting('memo:clearance');
        $viewer = new Caller(
            self::verified('actor:clerk'),
            new ToolContext(principal: 'clerk', channel: 'web', scopes: ['graph:decide', 'vault:open'], ip: '10.20.30.40', userAgent: 'Panel/9.9', extra: ['session.token' => 'tok-SECRET']),
        );

        $said = (string) json_encode($this->runs->pending($viewer));

        foreach (['vault:open', 'graph:decide', '10.20.30.40', 'Panel/9.9', 'tok-SECRET'] as $held) {
            self::assertStringNotContainsString($held, $said, "'{$held}' is what the viewer holds, and no row says it");
        }
    }

    public function testSayingItWritesNothing(): void
    {
        // Judging an ANSWER leaves a refusal in the log. Judging what to SHOW is not an answer: the log is untouched,
        // however many people look and whatever they may not do.
        $this->waiting('memo:clearance');
        $before = (string) file_get_contents($this->path);

        $this->runs->pending();
        $this->runs->pending(self::viewer('actor:clerk', 'clerk', 'graph:decide'));
        $this->runs->pending(self::viewer('actor:agent-7', 'agent-7', '*'));
        $this->runs->pending(new Caller());

        self::assertSame($before, (string) file_get_contents($this->path));
    }

    /**
     * Who looks, and what the surface verified they hold.
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function viewers(): iterable
    {
        yield 'a clerk who may decide and may not release' => ['actor:clerk', ['graph:decide']];
        yield 'an officer who may decide and release' => ['actor:officer', ['graph:decide', 'memo:release']];
        yield 'somebody who holds everything' => ['actor:rod', ['*']];
        yield 'whoever started the run, holding everything' => ['actor:agent-7', ['*']];
        yield 'whoever started the run, holding the release' => ['actor:agent-7', ['graph:decide', 'memo:release']];
        yield 'whoever started the run, holding only the right to decide' => ['actor:agent-7', ['graph:decide']];
    }

    /**
     * THE CARD AND THE DOOR AGREE. For every viewer and every option: what `pending()` says they may take is exactly
     * what `graph:decide` takes from them, and what it says they may not is exactly what it refuses.
     *
     * @param list<string> $scopes
     */
    #[DataProvider('viewers')]
    public function testWhatItSaysIsWhatTheDoorDoes(string $actor, array $scopes): void
    {
        foreach (['release', 'shelve'] as $option) {
            $instance = $this->waiting('memo:clearance');
            $principal = substr($actor, \strlen('actor:'));
            $viewer = self::viewer($actor, $principal, ...$scopes);

            $rows = array_column($this->runs->pending($viewer), null, 'instance_id');
            $said = array_column($rows[$instance]['choices'], null, 'option')[$option];

            $answer = (new OperationRunner($this->container))->run(
                $this->operations['graph:decide'],
                ['graph' => 'memo:clearance', 'instance' => $instance, 'decision' => $option],
                'http',
                self::verified($actor),
                ToolContext::web($principal, $scopes),
            );

            self::assertSame($said['may'], ($answer['ok'] ?? true) !== false, "{$actor} · {$option}: " . json_encode($answer));
            if ($said['may'] === false) {
                self::assertStringContainsString((string) $said['why_not'], (string) ($answer['error'] ?? ''), 'and the reason it gave is the reason the door gives');
                self::assertSame('signoff', $this->runs->show('memo:clearance', $instance)['state'], 'the gate was not spent');
            }
        }
    }

    public function testTheOperationSaysWhereEachOptionLeads(): void
    {
        $this->waiting('memo:clearance');

        $listed = ($this->operations['graph:pending']->handler)([]);

        self::assertSame(1, $listed['count']);
        self::assertSame(['release', 'shelve'], array_column($listed['pending'][0]['choices'], 'option'));
        self::assertSame([['memo:release'], []], array_column($listed['pending'][0]['choices'], 'needs'));
    }

    public function testEveryWaitingRunIsSaidForItsOwnGraph(): void
    {
        $memo = $this->waiting('memo:clearance');
        $stamped = $this->waiting('memo:stamped');

        $rows = array_column($this->runs->pending(self::viewer('actor:clerk', 'clerk', 'graph:decide')), null, 'instance_id');

        self::assertSame('release', array_column($rows[$memo]['choices'], 'leads_to', 'option')['release']);
        self::assertSame('stamp', array_column($rows[$stamped]['choices'], 'leads_to', 'option')['release']);
        self::assertFalse(array_column($rows[$memo]['choices'], 'may', 'option')['release']);
        self::assertTrue(array_column($rows[$stamped]['choices'], 'may', 'option')['release']);
    }

    /** A run of `$graph` started by agent-7 and left at its gate. */
    private function waiting(string $graph): string
    {
        $started = $this->runs->start($graph, ['title' => 'Q3'], 'actor:agent-7', new Caller(self::verified('actor:agent-7'), ToolContext::web('agent-7', ['graph:run'])));
        self::assertSame('signoff', $started['state'], (string) json_encode($started));

        return (string) $started['instance_id'];
    }

    private static function viewer(string $actor, string $principal, string ...$scopes): Caller
    {
        return new Caller(self::verified($actor), ToolContext::web($principal, array_values($scopes)));
    }

    /** Who the surface authenticated, as it hands it to a handler. */
    private static function verified(string $actor): InvocationContext
    {
        return new InvocationContext(actor: $actor, verified: true, channel: 'web', authorizationId: 'test');
    }
}
