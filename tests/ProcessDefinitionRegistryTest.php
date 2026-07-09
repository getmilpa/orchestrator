<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\Tests\Fixtures\ChainLevelProcess;
use Milpa\Orchestrator\Tests\Fixtures\SampleProcess;
use PHPUnit\Framework\TestCase;

final class ProcessDefinitionRegistryTest extends TestCase
{
    public function testRegisteringAndRetrievingADefinitionByName(): void
    {
        $registry = new ProcessDefinitionRegistry();
        $definition = SampleProcess::build();

        $registry->register(SampleProcess::NAME, $definition);

        $this->assertTrue($registry->has(SampleProcess::NAME));
        $this->assertSame($definition, $registry->get(SampleProcess::NAME));
        $this->assertSame([SampleProcess::NAME], $registry->names());
    }

    public function testHasIsFalseForAnUnregisteredName(): void
    {
        $registry = new ProcessDefinitionRegistry();

        $this->assertFalse($registry->has('not_registered'));
    }

    public function testGettingAnUnregisteredNameThrows(): void
    {
        $registry = new ProcessDefinitionRegistry();

        $this->expectException(\RuntimeException::class);

        $registry->get('not_registered');
    }

    public function testRegisteringTwiceUnderTheSameNameOverwrites(): void
    {
        $registry = new ProcessDefinitionRegistry();
        $first = SampleProcess::build();
        $second = SampleProcess::build();

        $registry->register(SampleProcess::NAME, $first);
        $registry->register(SampleProcess::NAME, $second);

        $this->assertSame($second, $registry->get(SampleProcess::NAME));
    }

    public function testRegisteringASelfReferentialSubprocessDefinitionThrows(): void
    {
        $registry = new ProcessDefinitionRegistry();
        $definition = ChainLevelProcess::nonBottom('self_ref', 'self_ref');

        $this->expectException(\RuntimeException::class);

        $registry->register('self_ref', $definition);
    }

    public function testASelfReferentialRegistrationLeavesTheRegistryUntouched(): void
    {
        $registry = new ProcessDefinitionRegistry();

        try {
            $registry->register('self_ref', ChainLevelProcess::nonBottom('self_ref', 'self_ref'));
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($registry->has('self_ref'), 'a rejected registration must not leave a partial entry behind');
    }

    public function testRegisteringATransitiveCycleAcrossTwoDefinitionsThrowsOnTheCompletingRegistration(): void
    {
        $registry = new ProcessDefinitionRegistry();
        $registry->register('cycle_a', ChainLevelProcess::nonBottom('cycle_a', 'cycle_b'));

        $this->expectException(\RuntimeException::class);

        $registry->register('cycle_b', ChainLevelProcess::nonBottom('cycle_b', 'cycle_a'));
    }

    public function testAForwardReferenceToANotYetRegisteredDefinitionIsNotFlaggedAsACycle(): void
    {
        // 'chain_head' references 'chain_tail' BEFORE 'chain_tail' is registered — a dangling
        // forward reference, not (yet) a cycle; must register cleanly.
        $registry = new ProcessDefinitionRegistry();
        $registry->register('chain_head', ChainLevelProcess::nonBottom('chain_head', 'chain_tail'));
        $registry->register('chain_tail', ChainLevelProcess::bottom('chain_tail'));

        $this->assertTrue($registry->has('chain_head'));
        $this->assertTrue($registry->has('chain_tail'));
    }

    public function testALongNonCyclicChainOfSubprocessReferencesRegistersCleanly(): void
    {
        $registry = new ProcessDefinitionRegistry();
        $registry->register('level_2', ChainLevelProcess::bottom('level_2'));
        $registry->register('level_1', ChainLevelProcess::nonBottom('level_1', 'level_2'));
        $registry->register('level_0', ChainLevelProcess::nonBottom('level_0', 'level_1'));

        $this->assertSame(['level_2', 'level_1', 'level_0'], $registry->names());
    }
}
