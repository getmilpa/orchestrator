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
use Milpa\Orchestrator\Declaration\Caller;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one question a node asks before it runs — may whoever is driving this run do what I declare I need? — and the
 * one thing the log keeps about them.
 */
#[CoversClass(Caller::class)]
final class CallerTest extends TestCase
{
    public function testANodeThatDeclaresNothingRunsForAnybodyIncludingNobody(): void
    {
        self::assertNull((new Caller())->refusalOf(self::node()));
        self::assertNull((new Caller(authority: ToolContext::web('rod', [])))->refusalOf(self::node()));
    }

    public function testDeclaredScopesAreAlternativesAndHoldingAnyOneAdmitsTheCall(): void
    {
        $node = self::node(scopes: ['memo:release', 'memo:admin']);

        self::assertNull((new Caller(authority: ToolContext::web('rod', ['memo:admin'])))->refusalOf($node));
        self::assertNull((new Caller(authority: ToolContext::web('rod', ['*'])))->refusalOf($node), 'the wildcard an owner holds passes every scope');
        self::assertSame(
            'it needs one of the scopes memo:release, memo:admin, and actor:rod holds none of them',
            (new Caller(new InvocationContext(actor: 'actor:rod', verified: true), ToolContext::web('rod', ['memo:read'])))->refusalOf($node),
        );
    }

    public function testTheRefusalNamesWhoItRefusedAndNeverWhatTheyHold(): void
    {
        $node = self::node(scopes: ['memo:release']);
        $holding = ToolContext::web('rod', ['vault:open']);

        self::assertSame('it needs the scope memo:release, which actor:rod does not hold', (new Caller(new InvocationContext(actor: 'actor:rod', verified: true), $holding))->refusalOf($node));
        self::assertSame('it needs the scope memo:release, which rod does not hold', (new Caller(authority: $holding))->refusalOf($node), 'with no actor attributed, the principal the scopes were judged for');
    }

    public function testAnAuthorityNobodyIsNamedForHoldsNothing(): void
    {
        // Scopes with no principal behind them are scopes nobody can be named for — and the log is where each node
        // says who ran it. PolicyGate refuses the same call on every channel that requires a principal.
        $node = self::node(scopes: ['memo:release']);

        foreach ([null, ''] as $nobody) {
            $unnamed = new ToolContext(principal: $nobody, channel: 'web', scopes: ['memo:release', '*']);

            self::assertSame('it needs the scope memo:release, and this call names nobody its authority could be judged for', (new Caller(authority: $unnamed))->refusalOf($node));
            self::assertSame(
                "it needs the permission 'memo.archive:write', and this call names nobody its authority could be judged for",
                (new Caller(authority: $unnamed))->refusalOf(self::node(permission: 'memo.archive:write')),
            );
        }

        self::assertNull((new Caller(authority: new ToolContext(principal: null, channel: 'web', scopes: ['*'])))->refusalOf(self::node()), 'a node that needs nothing still asks nothing');
    }

    public function testAnAbsentAuthorityHoldsNothing(): void
    {
        $verified = new InvocationContext(actor: 'actor:rod', verified: true);

        self::assertSame('it needs the scope memo:release, and this call carries no authority to judge', (new Caller($verified))->refusalOf(self::node(scopes: ['memo:release'])));
        self::assertSame("it needs the permission 'memo.archive:write', and this call carries no authority to judge", (new Caller($verified))->refusalOf(self::node(permission: 'memo.archive:write')));
    }

    public function testAPermissionIsNotSomethingThisEngineCanJudgeSoOnlyTheWildcardPassesIt(): void
    {
        $node = self::node(permission: 'memo.archive:write');

        self::assertNull((new Caller(authority: ToolContext::web('rod', ['*'])))->refusalOf($node));
        self::assertSame(
            "it needs the permission 'memo.archive:write', and nothing here can judge a permission for rod — not knowing is not allowing",
            (new Caller(authority: ToolContext::web('rod', ['memo.archive:write', 'memo:release'])))->refusalOf($node),
            'a scope spelled like the permission is not the permission',
        );
    }

    public function testTheRecordKeepsWhoAndTheThreadToFollowAndNothingTheyHold(): void
    {
        $caller = new Caller(
            new InvocationContext(actor: 'key:ROD', verified: true, channel: 'cli', executor: 'www-data@prod-7', authorizationId: 'sha256:abc', correlationId: 'whatever the X-Request-Id header said'),
            new ToolContext(principal: 'rod', channel: 'cli', scopes: ['*'], ip: '10.0.0.1', extra: ['signer.fingerprint' => 'ROD']),
        );

        // Not the executor — the server's own account and host name, which a reader of a run has no business with —
        // and not the correlation id, which over HTTP is a header the caller writes.
        self::assertSame(
            ['actor' => 'key:ROD', 'verified' => true, 'channel' => 'cli', 'principal' => 'rod', 'authorization' => 'sha256:abc'],
            $caller->record(),
        );
        self::assertSame(['actor' => null, 'verified' => false, 'channel' => 'unknown', 'principal' => null], (new Caller())->record(), 'nobody is recorded as nobody');
        self::assertSame(
            ['actor' => null, 'verified' => false, 'channel' => 'mcp', 'principal' => 'stdio'],
            (new Caller(authority: ToolContext::stdio('req-1')))->record(),
            'a surface that attributes nothing still says where the call came in and whose scopes were judged',
        );
    }

    /** @param list<string> $scopes */
    private static function node(array $scopes = [], ?string $permission = null): Operation
    {
        return new Operation(name: 'memo:release', description: 'Release.', handler: static fn (): array => [], scopes: $scopes, permission: $permission);
    }
}
