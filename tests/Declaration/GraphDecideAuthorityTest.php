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
use Milpa\Command\OperationHttpPolicy;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationRunner;
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\Operations\DecideGraph;
use Milpa\Orchestrator\Operations\GraphOperations;
use Milpa\Orchestrator\Operations\StartGraph;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\EssayReview;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Verdict;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Writer;
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
 * Who answers a graph's gate is WHO THE SURFACE AUTHENTICATED, never a name the caller writes (greenhouse
 * decisions/0528).
 *
 * `graph:decide` used to take a `principal` input and judge author ≠ approver (D9) against it, and `graph:start`
 * took its `requester` the same way — so the agent that produced the work could approve it by naming anyone else,
 * on every surface. These tests drive the operations through the real surfaces a host mounts: MCP through
 * {@see McpProjector} into a {@see ToolRegistry}, HTTP through {@see HttpProjector} with the request's
 * `milpa.auth` (the passkey session the panel's door carries), and a signed terminal through the same
 * {@see OperationRunner} the CLI calls with the signer's context.
 */
#[CoversClass(GraphOperations::class)]
#[CoversClass(StartGraph::class)]
#[CoversClass(DecideGraph::class)]
#[CoversClass(GraphRuns::class)]
final class GraphDecideAuthorityTest extends TestCase
{
    private string $path = '';

    private GraphRuns $runs;

    /** @var array<string, Operation> */
    private array $operations = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        $writer = new Writer(array_fill(0, 6, Verdict::Failed));
        $registry = (new GraphRegistry(static fn (string $type): object => $writer))->register(EssayReview::class);
        $this->path = sys_get_temp_dir() . '/graph-authority-' . uniqid('', true) . '.jsonl';
        $this->runs = new GraphRuns(
            $registry,
            new FileEventStore($this->path),
            new HumanGate(new StubDecisionSurfaceFactory()),
            new EventDispatcher(new NullLogger()),
        );

        $services = [GraphRuns::class => $this->runs, OperationHttpPolicy::class => new AllowingHttpPolicy()];
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

    public function testNeitherTheApproverNorTheRequesterIsAnInputAnyMore(): void
    {
        self::assertArrayNotHasKey('principal', $this->operations['graph:decide']->inputSchema['properties'] ?? []);
        self::assertArrayNotHasKey('requester', $this->operations['graph:start']->inputSchema['properties'] ?? []);
    }

    public function testAnAgentOverMcpCannotApproveItsOwnGateByNamingSomeoneElse(): void
    {
        $tools = $this->mcp();
        $agent = ToolContext::stdio('mcp-1');

        // An honest agent: it names itself as the requester. It is the ANSWER that names someone else.
        $started = $tools->call('graph_start', ['graph' => 'essay:review', 'inputs' => $this->inputs(), 'requester' => 'agent-7'], $agent);
        self::assertTrue($started->success, (string) json_encode($started->toArray()));
        $instance = (string) $started->data['instance_id'];

        $decided = $tools->call('graph_decide', [
            'graph' => 'essay:review',
            'instance' => $instance,
            'decision' => 'publish_as_is',
            'principal' => 'rod',
        ], $agent);

        self::assertFalse($this->approved($decided->data ?? []), 'an agent over MCP approved its own gate by naming rod: ' . json_encode($decided->toArray()));
        self::assertSame(1, $this->pendingCount(), 'the gate is still waiting for a human');
    }

    public function testOverHttpTheActorThatOpenedTheGateCannotApproveItWhateverPrincipalItPosts(): void
    {
        $started = $this->http('graph:start', ['graph' => 'essay:review', 'inputs' => $this->inputs(), 'requester' => 'agent-7'], 'agent-7');
        $instance = (string) $started['instance_id'];

        $decided = $this->http('graph:decide', [
            'graph' => 'essay:review',
            'instance' => $instance,
            'decision' => 'publish_as_is',
            'principal' => 'rod',
        ], 'agent-7');

        self::assertFalse($this->approved($decided), 'the actor that opened the gate approved it by posting principal=rod: ' . json_encode($decided));
        self::assertStringContainsString('opened this gate', (string) ($decided['error'] ?? ''), 'refused for being its own approver, not for a scope it lacks');
        self::assertSame(1, $this->pendingCount());
    }

    public function testARealDifferentHumanApprovesAGateAnAgentOpenedOverMcp(): void
    {
        $started = $this->mcp()->call('graph_start', ['graph' => 'essay:review', 'inputs' => $this->inputs()], ToolContext::stdio('mcp-1'));
        $instance = (string) $started->data['instance_id'];

        // The panel still posts a principal until its release drops it; whatever it says, it is not read.
        $decided = $this->http('graph:decide', [
            'graph' => 'essay:review',
            'instance' => $instance,
            'decision' => 'abandon',
            'principal' => 'someone-else',
        ], 'rod');

        self::assertSame('abandon_done', $decided['state'] ?? null, (string) json_encode($decided));
        self::assertSame(0, $this->pendingCount());
        self::assertSame('actor:rod', $this->decidedBy($instance), 'the ledger records who the passkey session was, not what the body said');
    }

    public function testASignedTerminalCannotApproveTheGateItsOwnKeyOpened(): void
    {
        $runner = new OperationRunner($this->container);
        $seat = new InvocationContext(actor: 'key:SEAT', verified: true, channel: 'cli', authorizationId: 'sha256:x');

        $started = $runner->run($this->operations['graph:start'], ['graph' => 'essay:review', 'inputs' => $this->inputs(), 'requester' => 'seat'], 'cli', $seat);
        $instance = (string) $started['instance_id'];

        $self = $runner->run($this->operations['graph:decide'], ['graph' => 'essay:review', 'instance' => $instance, 'decision' => 'publish_as_is', 'principal' => 'rod'], 'cli', $seat, ToolContext::cli());
        self::assertFalse($this->approved($self), (string) json_encode($self));
        self::assertStringContainsString('opened this gate', (string) ($self['error'] ?? ''), 'refused for being its own approver, not for a scope it lacks');
        self::assertSame(1, $this->pendingCount());

        $human = new InvocationContext(actor: 'key:ROD', verified: true, channel: 'cli', authorizationId: 'sha256:y');
        $other = $runner->run($this->operations['graph:decide'], ['graph' => 'essay:review', 'instance' => $instance, 'decision' => 'abandon'], 'cli', $human);
        self::assertSame('abandon_done', $other['state'] ?? null, (string) json_encode($other));
        self::assertSame('key:ROD', $this->decidedBy($instance));
    }

    public function testAnUnattributedCallerCannotAnswerAGateAtAll(): void
    {
        $runner = new OperationRunner($this->container);
        $started = $runner->run($this->operations['graph:start'], ['graph' => 'essay:review', 'inputs' => $this->inputs()], 'cli', InvocationContext::cli('operator@example.com'));
        $instance = (string) $started['instance_id'];

        foreach ([null, InvocationContext::cli('operator@example.com'), new InvocationContext(actor: 'actor:rod', verified: false, channel: 'web')] as $context) {
            $answer = $runner->run($this->operations['graph:decide'], ['graph' => 'essay:review', 'instance' => $instance, 'decision' => 'abandon', 'principal' => 'rod'], 'cli', $context);

            self::assertFalse($answer['ok'] ?? true, (string) json_encode($answer));
            self::assertStringContainsString('verified', (string) ($answer['error'] ?? ''));
        }
        self::assertSame(1, $this->pendingCount());
    }

    public function testTheRequesterIsWhoStartedTheRunNotWhatTheInputsSay(): void
    {
        $runner = new OperationRunner($this->container);
        $rod = new InvocationContext(actor: 'key:ROD', verified: true, channel: 'cli');

        $signed = $runner->run($this->operations['graph:start'], ['graph' => 'essay:review', 'inputs' => '{"title":"Tides","rubric":"r","_requester":"nobody"}', 'requester' => 'nobody'], 'cli', $rod);
        $overMcp = $this->mcp()->call('graph_start', ['graph' => 'essay:review', 'inputs' => $this->inputs(), 'requester' => 'rod'], ToolContext::stdio('mcp-2'));

        self::assertSame('key:ROD', $this->runs->show('essay:review', (string) $signed['instance_id'])['context']['_requester']);
        self::assertSame(
            'unverified:unknown',
            $this->runs->show('essay:review', (string) $overMcp->data['instance_id'])['context']['_requester'],
            'MCP attributes nothing today, so the run records that — not the rod its arguments claimed',
        );
    }

    private function mcp(): ToolRegistry
    {
        $tools = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll(array_values($this->operations), $tools, $this->container);

        return $tools;
    }

    /**
     * One call through the HTTP projector as the panel's door makes it — the passkey session in `milpa.auth` —
     * walking the confirm ceremony the operation demands.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function http(string $operation, array $body, string $actor): array
    {
        $psr17 = new Psr17Factory();
        $projector = new HttpProjector(array_values($this->operations), $this->container, $psr17, $psr17, policy: new AllowingHttpPolicy());
        $route = null;
        foreach ($projector->routes() as $candidate) {
            if ($candidate->name === $operation) {
                $route = $candidate;
            }
        }
        self::assertNotNull($route);

        $auth = new class ($actor) {
            public object $actor;

            public function __construct(string $id)
            {
                $this->actor = (object) ['id' => $id, 'scopes' => ['graph:run', 'graph:decide', 'graph:read', 'essay:publish']];
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
            $token = (string) ($this->decode($response)['confirm_token'] ?? '');
            $response = $projector->handle($request->withHeader('Confirm-Token', $token));
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return \is_array($decoded) ? $decoded + ['_status' => $response->getStatusCode()] : ['_status' => $response->getStatusCode()];
    }

    /** @param array<string, mixed> $result */
    private function approved(array $result): bool
    {
        return ($result['ok'] ?? true) !== false && ($result['state'] ?? null) === 'publish_done';
    }

    private function pendingCount(): int
    {
        return \count($this->runs->pending());
    }

    private function decidedBy(string $instance): ?string
    {
        foreach (file($this->path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (\is_array($event) && ($event['stream_id'] ?? null) === $instance && isset($event['payload']['by'])) {
                return (string) $event['payload']['by'];
            }
        }

        return null;
    }

    private function inputs(): string
    {
        return '{"title":"Tides","rubric":"formal and metaphorical"}';
    }
}

/** A host that lets every authenticated caller through, so what is left to judge is the operation's own rule. */
final class AllowingHttpPolicy implements OperationHttpPolicy
{
    public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        return null;
    }
}
