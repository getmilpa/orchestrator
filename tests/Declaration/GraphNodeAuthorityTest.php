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
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\Declaration\NodeInvoker;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\Operations\DecideGraph;
use Milpa\Orchestrator\Operations\GraphOperations;
use Milpa\Orchestrator\Operations\StartGraph;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\MemoClearance;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

        self::assertSame('', $this->channel($started, 'releasedBy'), 'memo:release ran for a caller that does not hold memo:release');
        self::assertFalse($started['ok'] ?? true, 'the graph says the node did not run: ' . json_encode($started));
        self::assertStringContainsString('memo:release', (string) ($started['error'] ?? ''));
        self::assertSame('release', $started['state'] ?? null, 'the run is parked AT the node it could not run, not past it');
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
