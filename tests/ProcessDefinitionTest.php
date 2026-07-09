<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use PHPUnit\Framework\TestCase;

final class ProcessDefinitionTest extends TestCase
{
    public function testTheDefinitionHasTheThreeExpectedStates(): void
    {
        $states = SampleProcess::build()->states();
        sort($states);

        $this->assertSame(['done', 'draft', 'review_gate'], $states);
    }

    public function testDraftIsTheInitialState(): void
    {
        $this->assertSame('draft', SampleProcess::build()->initialState());
    }

    public function testReviewGateTransitionsAreExactlyApproveAndReject(): void
    {
        $transitions = SampleProcess::build()->transitionsFrom('review_gate');
        usort($transitions, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $this->assertSame(
            [
                ['name' => 'approve', 'to' => 'done'],
                ['name' => 'reject', 'to' => 'draft'],
            ],
            $transitions,
        );
    }

    public function testDraftTransitionsAreExactlySubmit(): void
    {
        $this->assertSame(
            [['name' => 'submit', 'to' => 'review_gate']],
            SampleProcess::build()->transitionsFrom('draft'),
        );
    }

    public function testDoneIsTerminalAndHasNoOutgoingTransitions(): void
    {
        $definition = SampleProcess::build();

        $this->assertTrue($definition->isTerminal('done'));
        $this->assertFalse($definition->isTerminal('draft'));
        $this->assertFalse($definition->isTerminal('review_gate'));
        $this->assertSame([], $definition->transitionsFrom('done'));
    }

    public function testReviewGateCarriesAGateDefinitionSharedByBothTransitions(): void
    {
        $definition = SampleProcess::build();

        $gate = $definition->gateFor('review_gate');

        $this->assertNotNull($gate);
        $this->assertSame('review_gate_gate', $gate->getCode());
        $this->assertSame('single', $gate->getApprovalPolicyValue());
        $this->assertNull($definition->gateFor('draft'), 'draft has no gated transitions');
        $this->assertNull($definition->gateFor('done'), 'done has no outgoing transitions at all');
    }

    public function testADefinitionBuiltWithADeliberateCycleThrows(): void
    {
        $a = (new StateDefinition())->setDomain('cyclic')->setCode('a')->setLabel('A')->setIsInitial(true);
        $b = (new StateDefinition())->setDomain('cyclic')->setCode('b')->setLabel('B');

        $aToB = (new TransitionDefinition())->setDomain('cyclic')->setCode('to_b')->setFromState($a)->setToState($b);
        $bToA = (new TransitionDefinition())->setDomain('cyclic')->setCode('to_a')->setFromState($b)->setToState($a);

        $this->expectException(\RuntimeException::class);

        new ProcessDefinition([$a, $b], [$aToB, $bToA]);
    }

    public function testTheRejectToDraftToSubmitLoopIsALegitimateCycleThatDoesNotThrow(): void
    {
        // review_gate --reject--> draft --submit--> review_gate is a real cycle in the raw
        // state graph, but it is broken by the human gate on review_gate (someone must decide
        // approve/reject each time round) — SampleProcess::build() below must NOT throw despite
        // it, unlike the fully-automatic cycle in the test above.
        $definition = SampleProcess::build();

        $reject = array_values(array_filter(
            $definition->transitionsFrom('review_gate'),
            static fn (array $t): bool => $t['name'] === 'reject',
        ))[0];

        $this->assertSame('draft', $reject['to']);
    }
}
