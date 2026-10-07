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
use Milpa\Orchestrator\Declaration\CallRefused;
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\Orchestrator\Declaration\GraphDeclarationException;
use Milpa\Orchestrator\Declaration\GraphRegistry;
use Milpa\Orchestrator\Declaration\GraphRuns;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\Operations\DecideGraph;
use Milpa\Orchestrator\Operations\GraphOperations;
use Milpa\Orchestrator\Operations\ResumeGraph;
use Milpa\Orchestrator\Operations\ShowGraphRun;
use Milpa\Orchestrator\Operations\StartGraph;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\MemoClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Clearance\OpenClearance;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\ReservedChannel;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * A call the house refuses is ANSWERED — `ok: false` and the sentence — not thrown.
 *
 * Some refusals of the graph operations were results already: a node its caller may not run, an answer that was not
 * recorded, a gate its own opener tried to approve. Others were exceptions: a run that is not waiting, a run named
 * under another graph, an answer the gate never offered, a graph nobody declared. To whoever calls they are the same
 * thing — the house understood the call and said no — and the difference showed on the surfaces: over HTTP a thrown
 * refusal is a 500 `internal_error` whose sentence stays in the log (greenhouse decisions/0583).
 *
 * So the operations answer them. {@see GraphRuns} still throws, for a host that drives it itself — and a graph that
 * does not COMPILE still throws through everything: that is the app's mistake, not the caller's.
 */
#[CoversClass(GraphOperations::class)]
#[CoversClass(StartGraph::class)]
#[CoversClass(DecideGraph::class)]
#[CoversClass(ResumeGraph::class)]
#[CoversClass(ShowGraphRun::class)]
#[CoversClass(GraphRuns::class)]
#[CoversClass(GraphRegistry::class)]
#[CoversClass(CallRefused::class)]
final class ARefusedCallIsAnsweredNotThrownTest extends TestCase
{
    private string $path = '';

    private GraphRuns $runs;

    /** @var array<string, Operation> */
    private array $operations = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/graph-refused-call-' . uniqid('', true) . '.jsonl';
        $this->declaring(MemoClearance::class, OpenClearance::class);
    }

    /**
     * The house as an app that declares these graphs: its runs, its container and the operations served from it.
     *
     * @param class-string ...$graphs
     */
    private function declaring(string ...$graphs): void
    {
        $registry = new GraphRegistry();
        foreach ($graphs as $graph) {
            $registry->register($graph);
        }
        $this->runs = new GraphRuns($registry, new FileEventStore($this->path), new HumanGate(new StubDecisionSurfaceFactory()), new EventDispatcher(new NullLogger()));

        $services = [GraphRuns::class => $this->runs];
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id): mixed => $services[$id] ?? throw new \RuntimeException("no {$id}"));
        $this->container = $container;

        $this->operations = [];
        foreach ((new GraphOperations($container))->operations() as $operation) {
            $this->operations[$operation->name] = $operation;
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Every call the house refuses because of what it names or where the run stands: the operation, what is sent
     * (the placeholders name a run of this test), and a fragment of the sentence.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function refusedCalls(): iterable
    {
        yield 'answering a run that is not waiting' => ['graph:decide', ['graph' => 'memo:clearance', 'instance' => '<parked>', 'decision' => 'release'], 'is not waiting for a decision'];
        yield 'answering a run that finished' => ['graph:decide', ['graph' => 'memo:clearance', 'instance' => '<finished>', 'decision' => 'release'], 'is not waiting for a decision'];
        yield 'an answer the gate never offered' => ['graph:decide', ['graph' => 'memo:clearance', 'instance' => '<waiting>', 'decision' => 'burn_it'], "'burn_it' is not a valid decision"];
        yield 'answering a run under another graph' => ['graph:decide', ['graph' => 'open:clearance', 'instance' => '<waiting>', 'decision' => 'release'], "is not a run of 'open:clearance'"];
        yield 'answering a run that does not exist' => ['graph:decide', ['graph' => 'memo:clearance', 'instance' => 'no-such-run', 'decision' => 'release'], "is not a run of 'memo:clearance'"];
        yield 'answering under a graph nobody declared' => ['graph:decide', ['graph' => 'nope', 'instance' => '<waiting>', 'decision' => 'release'], "No graph named 'nope'"];
        yield 'resuming a run that waits for a decision' => ['graph:resume', ['graph' => 'memo:clearance', 'instance' => '<waiting>'], 'is waiting for a decision: answer it with graph:decide'];
        yield 'resuming a run that finished' => ['graph:resume', ['graph' => 'memo:clearance', 'instance' => '<finished>'], 'has finished'];
        yield 'resuming a run under another graph' => ['graph:resume', ['graph' => 'open:clearance', 'instance' => '<parked>'], "is not a run of 'open:clearance'"];
        yield 'resuming under a graph nobody declared' => ['graph:resume', ['graph' => 'nope', 'instance' => '<parked>'], "No graph named 'nope'"];
        yield 'showing a run that does not exist' => ['graph:show', ['graph' => 'memo:clearance', 'instance' => 'no-such-run'], "is not a run of 'memo:clearance'"];
        yield 'showing a run under another graph' => ['graph:show', ['graph' => 'open:clearance', 'instance' => '<waiting>'], "is not a run of 'open:clearance'"];
        yield 'showing under a graph nobody declared' => ['graph:show', ['graph' => 'nope', 'instance' => '<waiting>'], "No graph named 'nope'"];
        yield 'starting a graph nobody declared' => ['graph:start', ['graph' => 'nope', 'inputs' => '{}'], "No graph named 'nope'. Declared: memo:clearance, open:clearance"];
        yield 'starting with inputs that are not a JSON object' => ['graph:start', ['graph' => 'memo:clearance', 'inputs' => 'not json at all'], 'must be a JSON object of starting channels'];
    }

    /** @param array<string, string> $input */
    #[DataProvider('refusedCalls')]
    public function testTheOperationAnswersItWithItsSentenceAndWritesNothing(string $operation, array $input, string $sentence): void
    {
        $input = $this->naming($input);
        $before = $this->log();

        $answer = (new OperationRunner($this->container))->run($this->operations[$operation], $input, 'cli', self::verified('actor:officer'), self::holding('officer', '*'));

        self::assertIsArray($answer);
        self::assertFalse($answer['ok'] ?? true, 'a refusal is a negative verdict: ' . json_encode($answer));
        self::assertStringContainsString($sentence, (string) ($answer['error'] ?? ''));
        self::assertSame(['ok', 'error'], array_keys($answer), 'the sentence, and nothing the call did not earn');
        self::assertSame($before, $this->log(), 'a refused call leaves the log as it was');
    }

    /** @param array<string, string> $input */
    #[DataProvider('refusedCalls')]
    public function testOverHttpTheSentenceReachesWhoeverCalled(string $operation, array $input, string $sentence): void
    {
        [$status, $body] = $this->http($operation, $this->naming($input));

        self::assertLessThan(500, $status, 'a call the house refused is not a server error: ' . json_encode($body));
        self::assertFalse($body['ok'] ?? true);
        self::assertStringContainsString($sentence, (string) ($body['error'] ?? ''), 'and its sentence is in the answer, not only in the log');
    }

    /** @param array<string, string> $input */
    #[DataProvider('refusedCalls')]
    public function testOverMcpTheSentenceIsInTheToolResult(string $operation, array $input, string $sentence): void
    {
        $tools = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll(array_values($this->operations), $tools, $this->container);

        $result = $tools->call(str_replace(':', '_', $operation), $this->naming($input), ToolContext::mcp('req-1', 'officer', ['*']));

        // MCP verifies nobody, and a gate is answered by a verified actor: over this surface `graph:decide` says
        // THAT before it looks at the run — as it did. Everything else says the sentence of the refusal.
        $expected = $operation === 'graph:decide' ? 'A gate is answered by a verified actor' : $sentence;
        self::assertStringContainsString($expected, (string) json_encode($result->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        self::assertFalse(\is_array($result->data) ? ($result->data['ok'] ?? null) : null, 'it is the operation\'s answer: ' . json_encode($result->toArray()));
    }

    public function testAnsweredThroughTheDeclaredHandlerToo(): void
    {
        // The class a surface derives its schema from answers the same way as the door that drives it.
        foreach ([[DecideGraph::class, ['decision' => 'release']], [ResumeGraph::class, []], [ShowGraphRun::class, []]] as [$class, $extra]) {
            $declared = \Milpa\Command\Declaration\DeclaredOperation::from($class, fn (string $type): object => $this->runs);

            $answer = ($declared->handler)(['graph' => 'memo:clearance', 'instance' => 'no-such-run'] + $extra, self::verified('actor:officer'));

            self::assertFalse($answer['ok'] ?? true, $class);
            self::assertStringContainsString("is not a run of 'memo:clearance'", (string) ($answer['error'] ?? ''));
        }
    }

    public function testTheGateIsNotSpentByAnAnswerItNeverOffered(): void
    {
        $waiting = $this->leftAt('<waiting>');

        $answer = (new OperationRunner($this->container))->run($this->operations['graph:decide'], ['graph' => 'memo:clearance', 'instance' => $waiting, 'decision' => 'burn_it'], 'http', self::verified('actor:officer'), self::holding('officer', '*'));

        self::assertStringContainsString('release, shelve', (string) ($answer['error'] ?? ''), 'the sentence says what the gate does offer');
        self::assertCount(1, $this->runs->pending(), 'and the gate is still waiting');
    }

    public function testAHostDrivingTheEngineItselfIsStillToldByException(): void
    {
        $waiting = $this->leftAt('<waiting>');
        $officer = new Caller(self::verified('actor:officer'), self::holding('officer', '*'));

        foreach ([
            fn (): array => $this->runs->decide('memo:clearance', 'no-such-run', 'release', 'actor:officer', $officer),
            fn (): array => $this->runs->decide('memo:clearance', $waiting, 'burn_it', 'actor:officer', $officer),
            fn (): array => $this->runs->resume('memo:clearance', $waiting, $officer),
            fn (): array => $this->runs->show('nope', $waiting),
            fn (): array => $this->runs->start('nope', [], 'actor:officer', $officer),
        ] as $call) {
            try {
                $call();
                self::fail('GraphRuns answered a call it refuses');
            } catch (CallRefused $refused) {
                // What it always was to whoever caught it: a GraphDeclarationException, and an InvalidArgumentException.
                self::assertInstanceOf(GraphDeclarationException::class, $refused);
                self::assertInstanceOf(\InvalidArgumentException::class, $refused);
            }
        }
    }

    public function testAGraphThatDoesNotCompileIsNotARefusalOfTheCall(): void
    {
        // The app declared a graph the compiler refuses. Whoever calls did nothing wrong, and nothing here answers
        // for the app: the failure is let out, as every failure is.
        $this->declaring(ReservedChannel::class);
        $name = 'lab:reserved';

        foreach ([
            'graph:start' => ['inputs' => '{}'],
            'graph:decide' => ['instance' => 'any-run', 'decision' => 'release'],
            'graph:resume' => ['instance' => 'any-run'],
            'graph:show' => ['instance' => 'any-run'],
        ] as $operation => $input) {
            try {
                (new OperationRunner($this->container))->run($this->operations[$operation], ['graph' => $name] + $input, 'cli', self::verified('actor:officer'), self::holding('officer', '*'));
                self::fail("{$operation} answered for a graph that does not compile, as if the caller had asked wrong");
            } catch (GraphDeclarationException $broken) {
                self::assertNotInstanceOf(CallRefused::class, $broken);
                self::assertStringContainsString('underscore', $broken->getMessage());
            }
        }

        [$status] = $this->http('graph:start', ['graph' => $name, 'inputs' => '{}']);
        self::assertSame(500, $status, 'and over HTTP it is the server\'s failure');
    }

    /**
     * @param array<string, string> $input
     *
     * @return array<string, string>
     */
    private function naming(array $input): array
    {
        if (isset($input['instance']) && str_starts_with($input['instance'], '<')) {
            $input['instance'] = $this->leftAt($input['instance']);
        }

        return $input;
    }

    /** A run of memo:clearance left where the placeholder says: parked by a refusal, waiting at its gate, or finished. */
    private function leftAt(string $placeholder): string
    {
        [$clean, $scopes] = match ($placeholder) {
            '<parked>' => [true, ['graph:run']],
            '<waiting>' => [false, ['graph:run']],
            '<finished>' => [true, ['graph:run', 'memo:release']],
        };

        return (string) $this->runs->start('memo:clearance', ['title' => 'Q3', 'clean' => $clean], 'actor:agent-7', new Caller(self::verified('actor:agent-7'), self::holding('agent-7', ...$scopes)))['instance_id'];
    }

    /**
     * One call through the HTTP projector, walking the confirm ceremony a mutating operation demands.
     *
     * @param array<string, string> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function http(string $operation, array $body): array
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

        $auth = new class () {
            public object $actor;

            public function __construct()
            {
                $this->actor = (object) ['id' => 'officer', 'scopes' => ['*']];
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

        // A server failure is logged by the projector; here it is the measurement, not noise for the run.
        $log = ini_set('error_log', '/dev/null');
        $response = $projector->handle($request);
        if ($response->getStatusCode() === 428) {
            $token = (string) (json_decode((string) $response->getBody(), true)['confirm_token'] ?? '');
            $response = $projector->handle($request->withHeader('Confirm-Token', $token));
        }
        ini_set('error_log', (string) $log);

        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    /** The log as it is on disk — empty while nothing was ever written. */
    private function log(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
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
