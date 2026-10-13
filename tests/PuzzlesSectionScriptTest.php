<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Puzzles place opens the section used last (docs/features/navigation README D6, PUZ-01): assets/puzzles_section.js
 * run under node (tests/puzzles-section-harness.mjs). A Puzzles section page records itself in localStorage
 * `msp:puzzles-section`, every Puzzles place link (tab bar / rail, compact top bar, docked Menu row) follows; with
 * nothing remembered My library for players, All puzzles for guests; a guest ignores "library"; blocked storage and
 * broken attributes never throw and leave the server's links. Without a turbo:load (review PUZ+COM Q4): a document
 * restored from the back/forward cache records and applies again, another tab's write moves the links of a page of no
 * section (a section page keeps its own and writes nothing).
 */
final class PuzzlesSectionScriptTest extends TestCase
{
    private const string MY_LIBRARY = '/en/puzzle-library/018d0000-0000-0000-0000-000000000001';

    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testOnlyTheFourSectionsAreRecordedUnderOneKey(): void
    {
        $results = self::results();

        self::assertSame('msp:puzzles-section', $results['key']);
        self::assertSame([
            'recorded' => ['library' => true, 'all' => true, 'marketplace' => true, 'next' => true, 'puzzles' => false, '' => false, 'null' => false, 'Marketplace' => false],
            'kept' => 'next',
        ], $results['recording']);
    }

    public function testNothingRememberedOpensMyLibraryForPlayersAndAllPuzzlesForGuests(): void
    {
        self::assertSame([
            'member' => self::MY_LIBRARY,
            'dockRow' => self::MY_LIBRARY,
            'guest' => '/en/puzzle',
            // Review PUZ+COM F2: the docked row's server href is the tab's default, nothing to change
            'changedMember' => 0,
            'changedGuest' => 0,
            'stored' => false,
        ], self::results()['defaults']);
    }

    public function testEveryPlaceLinkOpensTheSectionUsedLast(): void
    {
        self::assertSame([
            'tab' => '/en/marketplace',
            'compact' => '/en/marketplace',
            'dockRow' => '/en/marketplace',
            'changed' => 3,
            'again' => 0,
            'read' => 'marketplace',
        ], self::results()['remembered']);

        self::assertSame(['href' => '/en/what-to-solve-next', 'read' => 'next'], self::results()['ownPage']);
    }

    public function testAGuestIgnoresMyLibrary(): void
    {
        self::assertSame(['href' => '/en/puzzle', 'target' => '/en/puzzle'], self::results()['guestIgnoresLibrary']);
        self::assertSame(['read' => null, 'href' => self::MY_LIBRARY], self::results()['unknownStored']);
    }

    public function testBlockedStorageKeepsTheDefault(): void
    {
        $default = ['threw' => false, 'href' => self::MY_LIBRARY, 'read' => null, 'recorded' => false];

        self::assertSame([
            'throwing' => $default,
            'missing' => $default,
            'windowWithout' => null,
            'windowBlocked' => null,
            'noWindow' => null,
        ], self::results()['blockedStorage']);
    }

    public function testBrokenOrForeignLinksAreIgnored(): void
    {
        self::assertSame([
            'parsed' => ['all' => '/en/puzzle'],
            'brokenParsed' => [],
            'nullParsed' => [],
            'changed' => 0,
            'href' => '/en/puzzle',
        ], self::results()['hostile']);
    }

    public function testItRunsOnEveryTurboLoadAndAtOnceOnAParsedPage(): void
    {
        self::assertSame([
            'atOnce' => '/en/what-to-solve-next',
            'beforeLoad' => self::MY_LIBRARY,
            'afterLoad' => '/en/what-to-solve-next',
        ], self::results()['install']);
    }

    public function testABackForwardCacheRestoreAppliesAgain(): void
    {
        self::assertSame([
            'before' => self::MY_LIBRARY,
            // A plain pageshow comes with a turbo:load: nothing of its own
            'notPersisted' => self::MY_LIBRARY,
            'restored' => '/en/what-to-solve-next',
            'sectionPage' => '/en/marketplace',
            'recordedAgain' => 'marketplace',
        ], self::results()['bfcache']);
    }

    public function testAnotherTabsSectionMovesTheLinksOfAPageOfNoSection(): void
    {
        self::assertSame([
            'followed' => '/en/marketplace',
            'otherKey' => '/en/marketplace',
            'otherArea' => '/en/marketplace',
            'cleared' => self::MY_LIBRARY,
            'ownSection' => '/en/puzzle',
            'changedOnOwnSection' => 0,
            'writesOnEvent' => 0,
            'stillRemembered' => 'marketplace',
        ], self::results()['otherTab']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/puzzles-section-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
