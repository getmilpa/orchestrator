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

use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\Declaration\CompiledGraph;
use Milpa\Orchestrator\Declaration\DeclaredGraph;
use Milpa\Orchestrator\Declaration\GraphDeclarationException;
use Milpa\Orchestrator\Declaration\NodeInvoker;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessInstance;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\EssayReview;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\RiskyGraph;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Verdict;
use Milpa\Orchestrator\Tests\Declaration\Fixtures\Writer;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The declared graph, compiled and run on the engine that already existed.
 *
 * The load-bearing assertions are the last two: a loop that runs out of budget does not stop, it
 * ASKS — and the node that decides is not a human, yet the edge it takes is written exactly the way
 * a human's gate answer is. That symmetry is the whole thesis.
 */
#[CoversClass(DeclaredGraph::class)]
#[CoversClass(NodeInvoker::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\CompiledGraph::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\Graph::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\Start::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\Edge::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\Route::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\Ask::class)]
#[CoversClass(\Milpa\Orchestrator\Declaration\Appends::class)]
#[CoversClass(GraphDeclarationException::class)]
#[CoversClass(ProcessRunner::class)]
final class DeclaredGraphTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testTheDeclarationCompilesToTheMachineTheEngineAlreadyRuns(): void
    {
        $graph = DeclaredGraph::from(EssayReview::class, fn (string $type): object => new Writer());

        self::assertSame('essay:review', $graph->name);
        self::assertSame(['write', 'grade', 'publish', 'abandon'], array_keys($graph->operations));
        self::assertSame(
            ['title', 'rubric', 'draft', 'feedback', 'drafts', 'verdict', 'url'],
            array_keys($graph->channels),
        );
        self::assertSame('write', $graph->definition->initialState());
        self::assertSame(['drafts' => 'draft'], $graph->appends);
    }

    public function testTheEnumCasesAreTheEdgesAndTheOriginIsDerived(): void
    {
        $graph = DeclaredGraph::from(EssayReview::class, fn (string $type): object => new Writer());

        self::assertSame(Verdict::class, $graph->routing['grade']['enum']);
        self::assertSame(['PASSED', 'FAILED'], array_keys($graph->routing['grade']['map']));
        self::assertSame(
            ['PASSED', 'FAILED', 'editor_call'],
            array_column($graph->definition->transitionsFrom('grade'), 'name'),
        );
    }

    public function testATerminalIsAPlaceAndNotANodeSoEveryNodeRuns(): void
    {
        $graph = DeclaredGraph::from(EssayReview::class, fn (string $type): object => new Writer());

        self::assertFalse($graph->definition->isTerminal('publish'), 'a node that never runs is not a finished graph');
        self::assertTrue($graph->definition->isTerminal('publish_done'));
        self::assertSame(['publish_done'], array_column($graph->definition->transitionsFrom('publish'), 'name'));
    }

    public function testTheGateIsWhereTheDeclarationSaidAndOffersTheEnumsCases(): void
    {
        $graph = DeclaredGraph::from(EssayReview::class, fn (string $type): object => new Writer());

        self::assertNotNull($graph->definition->gateFor('editor_call'));
        self::assertNull($graph->definition->gateFor('grade'));
        self::assertSame(
            ['publish_as_is', 'one_more_round', 'abandon'],
            array_column($graph->definition->transitionsFrom('editor_call'), 'name'),
        );
    }

    public function testAPassingRunReachesTheEndAndTheNodesWroteTheirChannels(): void
    {
        [$state] = $this->drive([Verdict::Passed]);

        self::assertSame('publish_done', $state->currentState);
        self::assertSame('PASSED', $state->context['verdict']);
        self::assertCount(1, $state->context['drafts']);
        self::assertStringStartsWith('https://example.test/', $state->context['url']);
    }

    public function testAFailedVerdictSendsTheGraphBackAndTheFeedbackTravelsWithIt(): void
    {
        [$state, , $writer] = $this->drive([Verdict::Failed, Verdict::Passed]);

        self::assertSame('publish_done', $state->currentState);
        self::assertCount(2, $state->context['drafts'], 'the loop ran twice');
        self::assertSame(
            ['', 'Tighten the tone and keep the metaphor.'],
            $writer->drafted,
            'the second draft was written against the first grade — the feedback is a channel, not a variable in a driver',
        );
    }

    public function testAnExhaustedBudgetDoesNotStopTheRunItAsksAHuman(): void
    {
        [$state, $pending] = $this->drive(array_fill(0, 6, Verdict::Failed));

        self::assertSame('editor_call', $state->currentState);
        self::assertNotNull($pending, 'the budget ran out and the graph opened a gate instead of stopping');
        self::assertSame(
            ['publish_as_is', 'one_more_round', 'abandon'],
            $this->sorted($pending->options),
            'the options a human is offered ARE the cases of the enum the routes were declared with',
        );
    }

    public function testTheBudgetIsCountedFromTheLogAndIsExactlyWhatWasDeclared(): void
    {
        [, , , $store, $instance] = $this->drive(array_fill(0, 6, Verdict::Failed));

        $failed = 0;
        foreach ($store->replay($instance->instanceId) as $event) {
            if ($event->type === 'FAILED') {
                ++$failed;
            }
        }

        self::assertSame(3, $failed, 'atMost: 3 means three, counted from the log and not from a counter someone keeps');
    }

    public function testANodeThatDemandsConfirmationIsRefusedRatherThanRunWithoutIt(): void
    {
        $this->expectException(GraphDeclarationException::class);
        $this->expectExceptionMessageMatches('/declares #\[Confirms\]/');

        DeclaredGraph::from(RiskyGraph::class);
    }

    public function testAClassThatDeclaresNothingIsNotAGraph(): void
    {
        self::assertTrue(DeclaredGraph::isDeclared(EssayReview::class));
        self::assertFalse(DeclaredGraph::isDeclared(Writer::class));

        $this->expectException(GraphDeclarationException::class);
        DeclaredGraph::from(Writer::class);
    }

    /**
     * @param list<Verdict> $verdicts
     *
     * @return array{0: \Milpa\Orchestrator\ProcessState, 1: ?\Milpa\Orchestrator\PendingDecision, 2: Writer, 3: FileEventStore, 4: ProcessInstance}
     */
    private function drive(array $verdicts): array
    {
        $writer = new Writer($verdicts);
        $graph = DeclaredGraph::from(EssayReview::class, static fn (string $type): object => $writer);

        $path = sys_get_temp_dir() . '/declared-graph-' . uniqid('', true) . '.jsonl';
        $this->paths[] = $path;
        $store = new FileEventStore($path);

        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), null, new NodeInvoker($graph));

        $instance = ProcessInstance::start($store, $graph->definition, ['title' => 'Tides', 'rubric' => 'formal and metaphorical']);
        $runner->advance($store, $instance, $gate, 'process');

        return [$instance->state($store), $gate->pendingFor($store, $instance), $writer, $store, $instance];
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>
     */
    private function sorted(array $options): array
    {
        sort($options);

        return array_values(array_reverse($options));
    }

    /**
     * Every way a graph can be wrong, refused by name at compile time.
     *
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function malformed(): iterable
    {
        yield 'a class nobody declared a graph on' => [Fixtures\Writer::class, '/is not a declared graph/'];
        yield 'a graph that never says where it begins' => [Fixtures\NoStart::class, '/declares no #\[Start\]/'];
        yield 'two nodes that compile to one state' => [Fixtures\DuplicateStates::class, '/two nodes compile to the same state/'];
        yield 'a node reading a channel nobody declared' => [Fixtures\StrangerChannel::class, '/which is not a channel of this graph/'];
        yield 'a graph with no constructor' => [Fixtures\Stateless::class, '/has no constructor/'];
        yield 'a constructor that promotes nothing' => [Fixtures\Channelless::class, '/declares no channels/'];
        yield 'a node that is not an operation' => [Fixtures\Mute::class, '/is not a usable operation/'];
        yield 'a budget of zero' => [Fixtures\ZeroBudget::class, '/A budget of zero is not a loop/'];
        yield 'a budget that simply stops' => [Fixtures\BudgetWithoutAsk::class, '/but no thenAsk/'];
        yield 'a loop with no escape at all' => [Fixtures\Unbudgeted::class, '/does not compile: .*cycle/'];
        yield 'a routing enum with a case nobody routed' => [Fixtures\Partial::class, '/declares no #\[Route\] for case Failed/'];
        yield 'one state routing on two enums' => [Fixtures\TwoEnums::class, '/routes on two different enums/'];
        yield 'an #[Ask] that names something with no cases' => [Fixtures\NotAnEnum::class, '/which is not an enum/'];
        yield 'a decision nobody produces' => [Fixtures\Orphan::class, '/has no origin/'];
        yield 'a from: naming something that is not an operation' => [Fixtures\StrangerOrigin::class, '/is not a usable operation/'];
        yield 'a class that does not exist' => ['Milpa\Orchestrator\Tests\Declaration\Fixtures\Nope', '/the class does not exist/'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedGraphIsRefusedByNameAtCompileTime(string $class, string $expected): void
    {
        $this->expectException(GraphDeclarationException::class);
        $this->expectExceptionMessageMatches($expected);

        /** @var class-string $class */
        DeclaredGraph::from($class, static fn (string $type): object => new Writer());
    }

    public function testANodeThatSaysItDecidesAndThenDoesNotIsRefusedAtRunTime(): void
    {
        $silent = new CompiledGraph(
            'lab:silent',
            'A node that claims to decide.',
            DeclaredGraph::from(EssayReview::class, static fn (string $type): object => new Writer())->definition,
            ['silent' => \Milpa\Command\Declaration\DeclaredOperation::from(Fixtures\Silent::class)],
            ['silent' => ['enum' => Verdict::class, 'map' => ['PASSED' => 'PASSED']]],
            ['title' => ''],
            [],
            [],
            [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/routes on .* but returned no value of it/');

        (new NodeInvoker($silent))->invoke('silent', ['title' => 'x'], [['name' => 'PASSED', 'to' => 'publish']]);
    }

    public function testAGateThatDemandsEvidenceCarriesThatDemandIntoTheCompiledDefinition(): void
    {
        $graph = DeclaredGraph::from(Fixtures\WithEvidence::class, static fn (string $type): object => new Writer());
        $gate = $graph->definition->gateFor('editor_call');

        self::assertNotNull($gate);
        self::assertSame(
            [\Milpa\Workflow\Enums\EvidenceType::RISK_ASSESSMENT],
            $gate->getRequiredEvidenceTypes(),
            'a gate that will not open on a promise says so in the compiled machine, not only in the declaration',
        );
    }

    public function testANodeCannotRouteSomewhereItNeverDeclared(): void
    {
        $graph = DeclaredGraph::from(EssayReview::class, static fn (string $type): object => new Writer());
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), null, new class () implements \Milpa\Orchestrator\NodeInvokerInterface {
            /**
             * A node that answers with an edge nobody declared.
             *
             * @param array<string, mixed>                  $context
             * @param list<array{name: string, to: string}> $transitions
             *
             * @return array{0: string, 1: array<string, mixed>}
             */
            public function invoke(string $state, array $context, array $transitions): array
            {
                return ['teleport', []];
            }
        });

        $path = sys_get_temp_dir() . '/declared-graph-' . uniqid('', true) . '.jsonl';
        $this->paths[] = $path;
        $store = new FileEventStore($path);
        $instance = ProcessInstance::start($store, $graph->definition, ['title' => 'x', 'rubric' => 'y']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/chose transition .teleport., which does not leave that state/');

        $runner->advance($store, $instance, new HumanGate(new StubDecisionSurfaceFactory()), 'process');
    }

    public function testAGateStateHasNoNodeSoTheEnginesOwnBehaviourStands(): void
    {
        $graph = DeclaredGraph::from(EssayReview::class, static fn (string $type): object => new Writer());

        self::assertNull((new NodeInvoker($graph))->invoke('editor_call', [], [['name' => 'abandon', 'to' => 'abandon']]));
    }

    public function testTheRunResumesWhereTheHumanLeftItAndTheChosenEdgeIsTaken(): void
    {
        [, $pending, , $store, $instance] = $this->drive(array_fill(0, 6, Verdict::Failed));
        self::assertNotNull($pending);

        $graph = DeclaredGraph::from(EssayReview::class, static fn (string $type): object => new Writer());
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()), null, new NodeInvoker($graph));

        $gate->resolve($store, $instance, $pending->gateId, 'abandon', 'editor');
        $runner->advance($store, $instance, $gate, 'process');

        self::assertSame('abandon_done', $instance->currentState($store), 'the human chose, and the graph went where that case declared');
    }

    public function testTheGateRefusesTheSamePrincipalThatOpenedIt(): void
    {
        [, $pending, , $store, $instance] = $this->drive(array_fill(0, 6, Verdict::Failed));
        self::assertNotNull($pending);

        $gate = new HumanGate(new StubDecisionSurfaceFactory());

        $this->expectException(\Milpa\Workflow\Exceptions\SelfApprovalException::class);
        $gate->resolve($store, $instance, $pending->gateId, 'abandon', 'process');
    }
}
