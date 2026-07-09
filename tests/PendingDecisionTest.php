<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\Orchestrator\PendingDecision;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurface;
use PHPUnit\Framework\TestCase;

final class PendingDecisionTest extends TestCase
{
    public function testConstructingWithMatchingOptionsSucceeds(): void
    {
        $pending = new PendingDecision(
            instanceId: 'proc-1',
            gateId: 'review_gate_gate',
            assignee: 'reviewer',
            artifact: new StubDecisionSurface(['approve', 'reject']),
            options: ['approve', 'reject'],
        );

        $this->assertSame(['approve', 'reject'], $pending->options);
    }

    public function testConstructingWithMatchingOptionsInADifferentOrderSucceeds(): void
    {
        // The invariant is order-insensitive — only the SET of names must match.
        $pending = new PendingDecision(
            instanceId: 'proc-1',
            gateId: 'review_gate_gate',
            assignee: 'reviewer',
            artifact: new StubDecisionSurface(['reject', 'approve']),
            options: ['approve', 'reject'],
        );

        $this->assertSame(['approve', 'reject'], $pending->options);
    }

    public function testConstructingWithFewerArtifactOptionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PendingDecision(
            instanceId: 'proc-1',
            gateId: 'review_gate_gate',
            assignee: 'reviewer',
            artifact: new StubDecisionSurface(['approve']),
            options: ['approve', 'reject'],
        );
    }

    public function testConstructingWithAnUnrelatedExtraArtifactOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PendingDecision(
            instanceId: 'proc-1',
            gateId: 'review_gate_gate',
            assignee: 'reviewer',
            artifact: new StubDecisionSurface(['approve', 'reject', 'escalate']),
            options: ['approve', 'reject'],
        );
    }
}
