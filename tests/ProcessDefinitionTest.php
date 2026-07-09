<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Orchestrator\SubprocessSpec;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use Milpa\Orchestrator\Tests\Fixtures\SubprocessParentProcess;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;
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

    public function testASubprocessStateExposesItsSpecAndIsReportedAsSuch(): void
    {
        $definition = SubprocessParentProcess::build();

        $spec = $definition->subprocessFor(SubprocessParentProcess::STATE_REVIEW);

        $this->assertNotNull($spec);
        $this->assertSame(SampleProcess::NAME, $spec->definitionRef);
        $this->assertTrue($definition->isSubprocess(SubprocessParentProcess::STATE_REVIEW));
    }

    public function testANonSubprocessStateReturnsNullSpecAndIsNotReportedAsOne(): void
    {
        $definition = SubprocessParentProcess::build();

        $this->assertNull($definition->subprocessFor(SubprocessParentProcess::STATE_KICKOFF));
        $this->assertFalse($definition->isSubprocess(SubprocessParentProcess::STATE_KICKOFF));
    }

    public function testASubprocessSpecReferencingAnUnknownStateThrows(): void
    {
        $only = (new StateDefinition())->setDomain('x')->setCode('only')->setLabel('Only')->setIsInitial(true)->setIsTerminal(true);

        $this->expectException(\RuntimeException::class);

        new ProcessDefinition([$only], [], ['not_a_state' => new SubprocessSpec(definitionRef: 'child')]);
    }

    public function testASubprocessStateThatIsAlsoTerminalThrows(): void
    {
        $terminal = (new StateDefinition())->setDomain('x')->setCode('t')->setLabel('T')->setIsInitial(true)->setIsTerminal(true);

        $this->expectException(\RuntimeException::class);

        new ProcessDefinition([$terminal], [], ['t' => new SubprocessSpec(definitionRef: 'child')]);
    }

    public function testASubprocessStateThatAlsoCarriesAGateThrows(): void
    {
        $start = (new StateDefinition())->setDomain('x')->setCode('start')->setLabel('Start')->setIsInitial(true);
        $end = (new StateDefinition())->setDomain('x')->setCode('end')->setLabel('End')->setIsTerminal(true);

        $gate = (new GateDefinition())
            ->setDomain('x')
            ->setCode('g')
            ->setName('G')
            ->setRequesterRole('author')
            ->setApproverRole('reviewer')
            ->setApprovalPolicy(ApprovalPolicy::SINGLE);

        $transition = (new TransitionDefinition())->setDomain('x')->setCode('go')->setFromState($start)->setToState($end);
        $transition->addGateDefinition($gate);

        $this->expectException(\RuntimeException::class);

        new ProcessDefinition([$start, $end], [$transition], ['start' => new SubprocessSpec(definitionRef: 'child')]);
    }

    public function testASubprocessStateIsExemptFromTheUngatedCycleCheck(): void
    {
        // begin --submit--> subprocess --[named 'done']--> begin: a full-graph cycle, but the
        // subprocess state is an external-trigger checkpoint (like a gate) that breaks it — must
        // NOT throw, mirroring the gate-broken revise-and-resubmit loop SampleProcess proves.
        $begin = (new StateDefinition())->setDomain('x')->setCode('begin')->setLabel('Begin')->setIsInitial(true);
        $waiting = (new StateDefinition())->setDomain('x')->setCode('waiting')->setLabel('Waiting');

        $toWaiting = (new TransitionDefinition())->setDomain('x')->setCode('submit')->setFromState($begin)->setToState($waiting);
        $backToBegin = (new TransitionDefinition())->setDomain('x')->setCode('done')->setFromState($waiting)->setToState($begin);

        $definition = new ProcessDefinition(
            [$begin, $waiting],
            [$toWaiting, $backToBegin],
            ['waiting' => new SubprocessSpec(definitionRef: 'child')],
        );

        $this->assertTrue($definition->isSubprocess('waiting'));
    }
}
