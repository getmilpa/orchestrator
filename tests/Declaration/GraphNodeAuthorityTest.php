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
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\CompiledGraph;
use Milpa\Orchestrator\Declaration\DeclaredGraph;
use Milpa\Orchestrator\Declaration\GraphDeclarationException;
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\Declaration\NodeInvoker;
use Milpa\Orchestrator\Declaration\NodeRefused;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\Operations\DecideGraph;
use Milpa\Orchestrator\Operations\GraphOperations;
use Milpa\Orchestrator\Operations\StartGraph;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\MemoClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\OpenClearance;
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
 * A node of a graph runs AS SOMEBODY: it is handed who is running it, and it runs only if that caller holds what it
 * declares it needs.
 *
 * The root of a graph was already guarded — `graph:start` and `graph:decide` declare their own scopes and take the
 * requester and the approver from the authenticated context. The nodes were not: the engine called each one with its
 * input and nothing else, so a node asking for its `InvocationContext` received `null`, and a node declaring
 * `#[Needs(scopes: …)]` ran for any caller that could reach the root. These tests drive the graph through the same
 * {@see OperationRunner} every surface calls, with the context and the authority a surface hands it.
 */
#[CoversClass(GraphOperations::class)]
#[CoversClass(StartGraph::class)]
#[CoversClass(DecideGraph::class)]
#[CoversClass(GraphRuns::class)]
#[CoversClass(NodeInvoker::class)]
#[CoversClass(NodeRefused::class)]
#[CoversClass(Caller::class)]
final class GraphNodeAuthorityTest extends TestCase
{
    private string $path = '';

    private GraphRuns $runs;

    /** @var array<string, Operation> */
    private array $operations = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/graph-node-authority-' . uniqid('', true) . '.jsonl';
        $this->runs = new GraphRuns(
            (new GraphRegistry())->register(MemoClearance::class),
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

    public function testANodeIsHandedWhoIsRunningIt(): void
    {
        $started = $this->start(clean: true, context: self::verified('key:ROD'), authority: self::holding('rod', '*'));

        self::assertSame(
            'key:ROD',
            $this->channel($started, 'preparedBy'),
            'memo:prepare asks for its InvocationContext, and the graph ran it for key:ROD',
        );
    }

    public function testANodeDoesNotRunForACallerThatLacksTheScopeItDeclares(): void
    {
        // rod may start graphs. Nothing says rod may release memos, and memo:release says it needs that.
        $started = $this->start(clean: true, context: self::verified('actor:rod'), authority: self::holding('rod', 'graph:run'));

        self::assertNull($this->channel($started, 'releasedBy'), 'memo:release ran for a caller that does not hold memo:release');
        self::assertFalse($started['ok'] ?? true, 'the graph says the node did not run: ' . json_encode($started));
        self::assertStringContainsString('memo:release', (string) ($started['error'] ?? ''));
        self::assertSame('release', $started['state'] ?? null, 'the run is parked AT the node it could not run, not past it');
    }

    public function testACallThatCarriesNoAuthorityRunsOnlyTheNodesThatNeedNone(): void
    {
        // Closed by default: nobody vouched for this call, so the node that declares a need does not run.
        $started = $this->start(clean: true, context: self::verified('key:ROD'), authority: null);

        self::assertSame('key:ROD', $this->channel($started, 'preparedBy'), 'memo:prepare needs nothing and ran as before');
        self::assertFalse($started['ok'] ?? true, (string) json_encode($started));
        self::assertStringContainsString('carries no authority', (string) ($started['error'] ?? ''));
        self::assertNull($this->channel($started, 'releasedBy'));
    }

    public function testAfterAGateTheNodesRunAsWhoeverAnsweredIt(): void
    {
        // The agent that started the run may not release. The human who answers may — and it is the human's call.
        $started = $this->start(clean: false, context: self::verified('actor:agent-7'), authority: self::holding('agent-7', 'graph:run'));
        self::assertSame('signoff', $started['state'] ?? null, (string) json_encode($started));

        $answered = $this->decide($started, 'release', self::verified('actor:rod'), self::holding('rod', 'graph:decide', 'memo:release'));

        self::assertSame('release_done', $answered['state'] ?? null, (string) json_encode($answered));
        self::assertSame('actor:agent-7', $this->channel($started, 'preparedBy'), 'what ran before the gate ran as whoever started the run');
        self::assertSame('actor:rod', $this->channel($started, 'releasedBy'), 'what ran after it ran as whoever answered');
    }

    public function testAnAnswerDoesNotBorrowTheScopesOfWhoeverStartedTheRun(): void
    {
        // The starter held memo:release. The clerk who answers does not — and the starter's scopes did not wait at the gate.
        $started = $this->start(clean: false, context: self::verified('actor:agent-7'), authority: self::holding('agent-7', 'graph:run', 'memo:release'));

        $answered = $this->decide($started, 'release', self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));

        self::assertFalse($answered['ok'] ?? true, (string) json_encode($answered));
        self::assertStringContainsString('memo:release', (string) ($answered['error'] ?? ''));
        self::assertNull($this->channel($started, 'releasedBy'));
        self::assertSame('signoff', $answered['state'] ?? null, 'the answer was not recorded, so the run did not leave the gate');
        self::assertNotNull($answered['awaiting'] ?? null);
        self::assertCount(1, $this->runs->pending(), 'and the gate is still there for somebody who may answer it that way');

        // The same clerk may still answer the way that needs nothing…
        $second = $this->start(clean: false, context: self::verified('actor:agent-7'), authority: self::holding('agent-7', 'graph:run'));
        $shelved = $this->decide($second, 'shelve', self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));
        self::assertSame('shelve_done', $shelved['state'] ?? null, (string) json_encode($shelved));
        self::assertSame('actor:clerk', $this->channel($second, 'shelvedBy'));

        // …and a human who does hold memo:release answers the first gate.
        $released = $this->decide($started, 'release', self::verified('actor:rod'), self::holding('rod', 'graph:decide', 'memo:release'));
        self::assertSame('release_done', $released['state'] ?? null, (string) json_encode($released));
        self::assertSame('actor:rod', $this->channel($started, 'releasedBy'));
    }

    public function testTheLogSaysWhoRanEachNodeAndWhoWasRefused(): void
    {
        $started = $this->start(clean: false, context: self::verified('actor:agent-7'), authority: self::holding('agent-7', 'graph:run'));
        $this->decide($started, 'release', self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));
        $this->decide($started, 'release', self::verified('actor:rod'), self::holding('rod', 'graph:decide', 'memo:release'));

        $trail = $this->runs->show('memo:clearance', (string) $started['instance_id'])['trail'] ?? null;

        self::assertIsArray($trail);
        self::assertSame(
            [
                ['prepare', 'ran', 'actor:agent-7'],
                ['check', 'ran', 'actor:agent-7'],
                ['prepare', 'ran', 'actor:agent-7'],
                ['check', 'ran', 'actor:agent-7'],
                ['release', 'refused', 'actor:clerk'],
                ['release', 'ran', 'actor:rod'],
            ],
            array_map(static fn (array $step): array => [$step['node'], $step['outcome'], $step['by']['actor']], $trail),
        );

        $refused = $trail[4];
        self::assertSame('memo:release', $refused['operation']);
        self::assertSame(['memo:release'], $refused['needs']);
        self::assertSame('release', $refused['answer'], 'the answer the clerk gave and that was not taken');
        self::assertSame(['actor' => 'actor:clerk', 'verified' => true, 'channel' => 'web', 'principal' => 'clerk', 'authorization' => 'test'], $refused['by']);
    }

    public function testWhoRanANodeCannotBeWrittenByWhoeverStartsTheRun(): void
    {
        // The starting inputs are the caller's own JSON, and they land in the same log the trail is read from.
        $forged = ['node' => 'release', 'operation' => 'memo:release', 'by' => ['actor' => 'actor:rod', 'verified' => true]];
        $started = (new OperationRunner($this->container))->run(
            $this->operations['graph:start'],
            ['graph' => 'memo:clearance', 'inputs' => (string) json_encode(['title' => 'Q3', '_ran' => $forged, '_refused' => $forged])],
            'cli',
            self::verified('actor:agent-7'),
            self::holding('agent-7', 'graph:run'),
        );

        $shown = $this->runs->show('memo:clearance', (string) $started['instance_id']);

        self::assertSame(
            [['prepare', 'actor:agent-7'], ['check', 'actor:agent-7'], ['prepare', 'actor:agent-7'], ['check', 'actor:agent-7']],
            array_map(static fn (array $step): array => [$step['node'], $step['by']['actor']], $shown['trail']),
            'the trail holds what the engine wrote and nothing the inputs claimed',
        );
        self::assertArrayNotHasKey('_refused', $shown['context'], 'and the claim is not kept as a channel either');
        self::assertSame('actor:agent-7', $shown['context']['_ran']['by']['actor']);
    }

    public function testTheTrailIsNotReadFromHowARunWasStartedEvenBelowTheDoor(): void
    {
        // A run can be started without graph:start — a host driving the engine itself. Its ProcessStarted payload is
        // still whatever it was started with, so the trail does not read a node's record from there.
        $graph = DeclaredGraph::from(MemoClearance::class);
        $forged = ['node' => 'release', 'operation' => 'memo:release', 'by' => ['actor' => 'actor:rod', 'verified' => true]];
        $instance = ProcessInstance::start(new FileEventStore($this->path), $graph->definition, ['title' => 'Q3', '_definition' => 'memo:clearance', '_ran' => $forged, '_refused' => $forged]);

        self::assertSame([], $this->runs->show('memo:clearance', $instance->instanceId)['trail']);
    }

    public function testTheLoopBudgetCannotBeMovedByWhoeverStartsTheRun(): void
    {
        // `atMost: 1, thenAsk:` is what brings a human in. The count rides in the log under a reserved channel, and
        // the starting inputs land in that same log: a caller that could seed it would decide when a human is asked.
        $started = (new OperationRunner($this->container))->run(
            $this->operations['graph:start'],
            ['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3","_taken":{"DIRTY":-5}}'],
            'cli',
            self::verified('actor:agent-7'),
            self::holding('agent-7', 'graph:run'),
        );

        $trail = $this->runs->show('memo:clearance', (string) $started['instance_id'])['trail'];

        self::assertSame('signoff', $started['state'] ?? null);
        self::assertSame(['prepare', 'check', 'prepare', 'check'], array_column($trail, 'node'), 'one loop, as declared — not the six the inputs asked for');
    }

    public function testARunCannotBeMovedBySomebodyElseAnsweringADecoyThatNamesItAsItsParent(): void
    {
        // The clerk may not release. Their run parks at memo:release — and then they start a decoy claiming that run
        // as its parent, for an officer to answer.
        $clerk = [self::verified('actor:clerk'), self::holding('clerk', 'graph:run')];
        $parked = $this->start(true, ...$clerk);
        self::assertSame('release', $parked['state'] ?? null);

        $decoy = (new OperationRunner($this->container))->run(
            $this->operations['graph:start'],
            ['graph' => 'memo:clearance', 'inputs' => (string) json_encode(['title' => 'lunch menu', 'parent_instance_id' => $parked['instance_id'], 'parent_state' => 'release'])],
            'cli',
            ...$clerk,
        );
        $shelved = $this->decide($decoy, 'shelve', self::verified('actor:rod'), self::holding('rod', 'graph:decide', 'memo:release'));

        self::assertSame('shelve_done', $shelved['state'] ?? null, (string) json_encode($shelved));
        $shown = $this->runs->show('memo:clearance', (string) $parked['instance_id']);
        self::assertSame('release', $shown['state'], 'the officer answered the decoy, not this run');
        self::assertArrayNotHasKey('releasedBy', $shown['context']);
        self::assertArrayNotHasKey('parent_instance_id', $this->runs->show('memo:clearance', (string) $decoy['instance_id'])['context'], 'what is not a channel of the graph does not start with it');
    }

    public function testOnlyTheChannelsOfTheGraphAreTakenFromTheStartingInputs(): void
    {
        $started = (new OperationRunner($this->container))->run(
            $this->operations['graph:start'],
            ['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3","clean":true,"by":"actor:ceo","gate_id":"x","anything":1}'],
            'cli',
            self::verified('actor:rod'),
            self::holding('rod', '*'),
        );

        $context = $this->runs->show('memo:clearance', (string) $started['instance_id'])['context'];

        self::assertSame(['title' => 'Q3', 'clean' => true], array_intersect_key($context, ['title' => 1, 'clean' => 1]));
        foreach (['by', 'gate_id', 'anything'] as $stray) {
            self::assertArrayNotHasKey($stray, $context);
        }
    }

    public function testARunIsAnsweredAndShownOnlyAsARunOfItsOwnGraph(): void
    {
        // A second graph with the same gate and a release that needs nothing. Named in place of the run's own, its
        // nodes — not the run's — would be the ones judged and run.
        $registry = (new GraphRegistry())->register(MemoClearance::class)->register(OpenClearance::class);
        $runs = new GraphRuns($registry, new FileEventStore($this->path), new HumanGate(new StubDecisionSurfaceFactory()), new EventDispatcher(new NullLogger()));
        $clerk = new Caller(self::verified('actor:clerk'), self::holding('clerk', 'graph:decide'));

        $started = $runs->start('memo:clearance', ['title' => 'Q3'], 'actor:agent-7', new Caller(self::verified('actor:agent-7'), self::holding('agent-7', 'graph:run')));
        self::assertSame('signoff', $started['state']);

        foreach ([
            fn (): array => $runs->decide('open:clearance', $started['instance_id'], 'release', 'actor:clerk', $clerk),
            fn (): array => $runs->show('open:clearance', $started['instance_id']),
            fn (): array => $runs->show('memo:clearance', 'no-such-run'),
        ] as $call) {
            try {
                $call();
                self::fail('a run was reached under a graph it does not belong to');
            } catch (GraphDeclarationException $refused) {
                self::assertStringContainsString('is not a run of', $refused->getMessage());
            }
        }

        self::assertSame('signoff', $runs->show('memo:clearance', $started['instance_id'])['state'], 'the gate was not spent');
        self::assertCount(1, $runs->pending());
    }

    public function testAMissingInputIsRefusedByTheDoorTheWayTheDeclaredOperationRefusesIt(): void
    {
        foreach (['graph:start' => [], 'graph:decide' => ['graph' => 'memo:clearance', 'instance' => 'x']] as $operation => $input) {
            try {
                ($this->operations[$operation]->handler)($input, self::verified('actor:rod'), self::holding('rod', '*'));
                self::fail("{$operation} ran without a required input");
            } catch (DeclarationException $refused) {
                self::assertStringContainsString('missing required input', $refused->getMessage());
            }
        }
    }

    public function testWhatTheCallerHoldsNeverReachesTheLog(): void
    {
        $authority = new ToolContext(
            principal: 'rod',
            channel: 'web',
            scopes: ['graph:run', 'vault:open'],
            ip: '10.20.30.40',
            userAgent: 'Panel/9.9',
            extra: ['signer.fingerprint' => 'FPR-SECRET', 'session.token' => 'tok-SECRET'],
        );

        $this->start(clean: true, context: self::verified('actor:rod'), authority: $authority);

        $log = (string) file_get_contents($this->path);
        self::assertStringContainsString('"actor":"actor:rod"', $log, 'who ran the nodes is in the log…');
        foreach (['vault:open', 'graph:run', '10.20.30.40', 'Panel/9.9', 'FPR-SECRET', 'tok-SECRET'] as $held) {
            self::assertStringNotContainsString($held, $log, "…and '{$held}', which the caller holds, is not");
        }
    }

    public function testOverHttpTheScopesOfThePasskeySessionDecideWhichNodesRun(): void
    {
        $refused = $this->http('graph:start', ['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3","clean":true}'], 'rod', ['graph:run']);

        self::assertFalse($refused['ok'] ?? true, (string) json_encode($refused));
        self::assertSame('release', $refused['state'] ?? null);
        self::assertSame('actor:rod', $this->channel($refused, 'preparedBy'), 'the passkey session is who the nodes were handed');
        self::assertNull($this->channel($refused, 'releasedBy'));

        $released = $this->http('graph:start', ['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3","clean":true}'], 'rod', ['graph:run', 'memo:release']);

        self::assertSame('release_done', $released['state'] ?? null, (string) json_encode($released));
        self::assertSame('actor:rod', $this->channel($released, 'releasedBy'));
    }

    public function testOverMcpANodeRunsUnderTheAuthorityOfTheToolCall(): void
    {
        $tools = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll(array_values($this->operations), $tools, $this->container);
        $call = ['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3","clean":true}'];

        // A scoped token: it may start graphs, and nothing says it may release.
        $scoped = $tools->call('graph_start', $call, ToolContext::mcp('req-1', 'agent-7', ['graph:run']));
        self::assertSame(['agent-7'], $this->whoWasRefused(), (string) json_encode($scoped->toArray()));

        // The local stdio process holds the wildcard, so every node runs — and MCP attributes nothing today, which
        // the log says as it is: no actor, and the principal the scopes were judged for.
        $owner = $tools->call('graph_start', $call, ToolContext::stdio('req-2'));
        self::assertTrue($owner->success, (string) json_encode($owner->toArray()));

        $trail = $this->runs->show('memo:clearance', (string) $owner->data['instance_id'])['trail'];
        $last = $trail[\count($trail) - 1];
        self::assertSame(['release', 'ran'], [$last['node'], $last['outcome']]);
        self::assertSame(['actor' => null, 'verified' => false, 'channel' => 'mcp', 'principal' => 'stdio'], $last['by']);
    }

    public function testADeclaredRunIsNeverHandedAuthoritySoThroughItTheCallerHoldsNothing(): void
    {
        // A host that derives the operation itself, instead of listing GraphOperations, gets the declared handler:
        // (input, context). The nodes are still told who is running them; the ones that declare a need do not run.
        $start = DeclaredOperation::from(StartGraph::class, fn (string $type): object => $this->runs)->handler;
        $decide = DeclaredOperation::from(DecideGraph::class, fn (string $type): object => $this->runs)->handler;

        $clean = $start(['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3","clean":true}'], self::verified('actor:rod'));
        self::assertSame('actor:rod', $this->channel($clean, 'preparedBy'));
        self::assertFalse($clean['ok'] ?? true, (string) json_encode($clean));
        self::assertSame('release', $clean['state'] ?? null);

        $dirty = $start(['graph' => 'memo:clearance', 'inputs' => '{"title":"Q3"}'], self::verified('actor:agent-7'));
        $answer = ['graph' => 'memo:clearance', 'instance' => (string) $dirty['instance_id']];

        $released = $decide($answer + ['decision' => 'release'], self::verified('actor:rod'));
        self::assertFalse($released['ok'] ?? true, (string) json_encode($released));
        self::assertNotNull($released['awaiting'] ?? null, 'the gate is still waiting');

        $shelved = $decide($answer + ['decision' => 'shelve'], self::verified('actor:rod'));
        self::assertSame('shelve_done', $shelved['state'] ?? null, (string) json_encode($shelved));
        self::assertSame('actor:rod', $this->channel($dirty, 'shelvedBy'));
    }

    public function testAnAnswerTheGateNeverOfferedIsStillRefusedByTheGateByName(): void
    {
        $started = $this->start(clean: false, context: self::verified('actor:agent-7'), authority: self::holding('agent-7', 'graph:run'));

        try {
            $this->decide($started, 'burn_it', self::verified('actor:rod'), self::holding('rod', '*'));
            self::fail('an answer the gate never offered was taken');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString("'burn_it' is not a valid decision", $refused->getMessage());
        }

        self::assertCount(1, $this->runs->pending());
    }

    public function testTheDoorChangesOnlyTheHandlerOfTheOperationsThatDriveARun(): void
    {
        // What a surface reads — name, schema, scopes, effects, target — is still derived from the declared class.
        foreach ([StartGraph::class, DecideGraph::class] as $class) {
            $declared = get_object_vars(DeclaredOperation::from($class, fn (string $type): object => $this->runs));
            $served = get_object_vars($this->operations[$declared['name']]);

            self::assertNotSame($declared['handler'], $served['handler']);
            unset($declared['handler'], $served['handler']);
            self::assertEquals($declared, $served, "{$class} reaches a surface with something other than its handler changed");
        }
    }

    public function testANodeIsCalledTheWayASurfaceCallsEveryHandler(): void
    {
        // Input, then who to attribute it to, then the caller's authority: a node that drives governed calls of its
        // own judges them against that authority, instead of finding none and assuming a local shell's wildcard.
        $received = [];
        $driver = new Operation(
            name: 'memo:file',
            description: 'File.',
            handler: static function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null) use (&$received): array {
                $received = [$input, $context, $authority];

                return [];
            },
            inputSchema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
        );
        $graph = new CompiledGraph('lab', 'lab', DeclaredGraph::from(MemoClearance::class)->definition, ['file' => $driver], [], ['title' => null], [], [], []);
        $context = self::verified('actor:rod');
        $authority = self::holding('rod', 'memo:file');

        (new NodeInvoker($graph, new Caller($context, $authority)))->invoke('file', ['title' => 'Q3', 'stray' => 1], [['name' => 'file_done', 'to' => 'file_done']]);

        self::assertSame([['title' => 'Q3'], $context, $authority], $received);
    }

    public function testDrivenWithoutACallerTheEngineRefusesAScopedNodeBeforeItsHandlerIsReached(): void
    {
        $reached = false;
        $scoped = new Operation(
            name: 'memo:release',
            description: 'Release.',
            handler: static function () use (&$reached): array {
                $reached = true;

                return [];
            },
            scopes: ['memo:release'],
        );
        $graph = new CompiledGraph('lab', 'lab', DeclaredGraph::from(MemoClearance::class)->definition, ['release' => $scoped], [], ['title' => null], [], [], []);

        try {
            (new NodeInvoker($graph))->invoke('release', ['title' => 'Q3'], [['name' => 'release_done', 'to' => 'release_done']]);
            self::fail('a node that declares a scope ran with nobody driving it');
        } catch (NodeRefused $refused) {
            self::assertSame(['release', 'memo:release', ['memo:release']], [$refused->node, $refused->operation, $refused->needs]);
        }

        self::assertFalse($reached, 'refused means the handler was never called');
    }

    /**
     * @return array<string, mixed>
     */
    private function start(bool $clean, ?InvocationContext $context, ?ToolContext $authority): array
    {
        /** @var array<string, mixed> $result */
        $result = (new OperationRunner($this->container))->run(
            $this->operations['graph:start'],
            ['graph' => 'memo:clearance', 'inputs' => (string) json_encode(['title' => 'Q3', 'clean' => $clean])],
            'cli',
            $context,
            $authority,
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $started
     *
     * @return array<string, mixed>
     */
    private function decide(array $started, string $decision, ?InvocationContext $context, ?ToolContext $authority): array
    {
        /** @var array<string, mixed> $result */
        $result = (new OperationRunner($this->container))->run(
            $this->operations['graph:decide'],
            ['graph' => 'memo:clearance', 'instance' => (string) $started['instance_id'], 'decision' => $decision],
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
     * The principals the log says were refused a node, in order.
     *
     * @return list<string|null>
     */
    private function whoWasRefused(): array
    {
        $who = [];
        foreach (file($this->path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (\is_array($event) && ($event['type'] ?? null) === GraphRuns::NODE_REFUSED) {
                $who[] = $event['payload'][GraphRuns::REFUSED]['by']['principal'] ?? null;
            }
        }

        return $who;
    }

    /** @param array<string, mixed> $position */
    private function channel(array $position, string $channel): mixed
    {
        return $this->runs->show('memo:clearance', (string) $position['instance_id'])['context'][$channel] ?? null;
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
