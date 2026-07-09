<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\Orchestrator\ProcessDefinitionRegistry;
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
}
