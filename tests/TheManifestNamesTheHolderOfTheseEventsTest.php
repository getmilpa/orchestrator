<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests;

use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Orchestrator\Event\OrchestratorEvents;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier for the second slice of greenhouse decisions/0228: this package NAMES its event
 * holder in its own manifest, so a host can read the events without constructing an emitter.
 *
 * Measured on cattle (greenhouse evidence/0567), a CLI process listed seven of the family's
 * twenty-four events, because an emitter declares when it is BUILT and a CLI run never builds a
 * ProcessRunner. The manifest is how the declaration of an emitter nobody constructed still
 * arrives. So this test reads `composer.json` FROM DISK — the same file Composer resolves into
 * `vendor/composer/installed.json` — and never the prose about it: a typo'd, renamed or deleted
 * `extra.milpa.events` entry goes red here, and so does a holder that stops being a holder.
 */
final class TheManifestNamesTheHolderOfTheseEventsTest extends TestCase
{
    /**
     * Retyped on purpose rather than read back from the manifest: a list that came from the file
     * under test could never disagree with it.
     */
    private const array EXPECTED_HOLDERS = [OrchestratorEvents::class];

    public function testTheManifestNamesExactlyThisPackagesEventHolders(): void
    {
        $this->assertSame(self::EXPECTED_HOLDERS, $this->holdersNamedInTheManifest());
    }

    public function testEveryClassTheManifestNamesExistsAndIsAHolder(): void
    {
        $named = $this->holdersNamedInTheManifest();
        $this->assertNotSame([], $named, 'a manifest that names nothing would pass every other assertion for free');

        foreach ($named as $class) {
            $this->assertTrue(class_exists($class), sprintf('the manifest names «%s», which no autoloader can find', $class));
            $this->assertTrue(is_a($class, DeclaresEvents::class, true), sprintf('«%s» is named as an event holder but does not implement %s', $class, DeclaresEvents::class));
        }
    }

    public function testTheClassNamedInTheManifestDeclaresTheSameEventsTheHolderDoes(): void
    {
        $fromTheManifest = [];
        foreach ($this->holdersNamedInTheManifest() as $class) {
            $this->assertTrue(is_a($class, DeclaresEvents::class, true));
            /** @var class-string<DeclaresEvents> $class */
            $fromTheManifest = [...$fromTheManifest, ...$this->namesOf($class::declarations())];
        }

        sort($fromTheManifest);
        $fromTheHolder = $this->namesOf(OrchestratorEvents::declarations());
        sort($fromTheHolder);

        $this->assertNotSame([], $fromTheHolder, 'a holder that declares nothing makes this comparison vacuous');
        $this->assertSame($fromTheHolder, $fromTheManifest);
    }

    /**
     * Reads `extra.milpa.events` out of this package's own manifest, next to the tests directory.
     *
     * @return list<string>
     */
    private function holdersNamedInTheManifest(): array
    {
        $path = dirname(__DIR__) . '/composer.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw, sprintf('cannot read %s', $path));

        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('extra', $manifest, 'the manifest has no «extra» at all');
        $this->assertIsArray($manifest['extra']);
        $this->assertArrayHasKey('milpa', $manifest['extra'], 'the manifest has no «extra.milpa»');
        $this->assertIsArray($manifest['extra']['milpa']);
        $this->assertArrayHasKey('events', $manifest['extra']['milpa'], 'the manifest names no event holder under «extra.milpa.events»');

        $events = $manifest['extra']['milpa']['events'];
        $this->assertIsArray($events, '«extra.milpa.events» must be a list of fully-qualified class names');
        $this->assertSame(array_keys($events), range(0, count($events) - 1), '«extra.milpa.events» must be a LIST, not an object');

        $holders = [];
        foreach ($events as $class) {
            $this->assertIsString($class);
            $holders[] = $class;
        }

        return $holders;
    }

    /**
     * @param list<EventDeclaration> $declarations
     *
     * @return list<string>
     */
    private function namesOf(array $declarations): array
    {
        return array_map(static fn (EventDeclaration $declaration): string => $declaration->name, $declarations);
    }
}
