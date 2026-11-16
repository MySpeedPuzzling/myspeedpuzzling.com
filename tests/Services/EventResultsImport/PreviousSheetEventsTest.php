<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventResultsImport;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\ResultsImport\StoredResultsTab;
use SpeedPuzzling\Web\Services\EventResultsImport\PreviousSheetEvents;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\PreviousSheetMatch;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The duplicate safeguard after a change of the spreadsheet (docs/features/event-results-import/results-import-page.md
 * "As built: PR 4"): an event without an imported item under its key is the event imported before when exactly one
 * imported event stored under a key the sheet no longer has has its date and name (folded); every uncertain case is a
 * problem, never a guess. Made-up events.
 */
final class PreviousSheetEventsTest extends TestCase
{
    public function testAnEventOfTheNewSheetIsTheImportedOneWithItsDateAndFoldedName(): void
    {
        $matches = PreviousSheetEvents::match(
            [self::event('901', '  MAPLE   Níght ', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05')],
        );

        self::assertSame(['901'], array_map('strval', array_keys($matches)));
        self::assertSame('101', $matches['901']->storedKey);
        self::assertFalse($matches['901']->isProblem());
        self::assertFalse($matches['901']->replacesUnimportedItem);
    }

    public function testAnotherDayOrAnotherNameIsAnotherEvent(): void
    {
        self::assertSame([], PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-06 19:00'), self::event('902', 'Maple Night 2', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05')],
        ));
    }

    public function testAKeyStillInTheSheetOrAnEventImportedUnderItsOwnKeyIsNeverMatched(): void
    {
        // 101 is still in the sheet - its namesake is another event of the same day (the organizer's call)
        self::assertSame([], PreviousSheetEvents::match(
            [self::event('101', 'Maple Night', '2026-10-05 19:00'), self::event('901', 'Maple Night', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05')],
        ));

        // 901 is imported under its own key already
        self::assertSame([], PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05'), '901' => self::stored('901', 'Other Night', '2026-10-09')],
        ));
    }

    public function testOnlyImportedEventsAreMatched(): void
    {
        self::assertSame([], PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05', imported: false)],
        ));
    }

    public function testANeverImportedItemUnderTheEventsKeyIsReplaced(): void
    {
        $matches = PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05'), '901' => self::stored('901', null, null, imported: false)],
        );

        self::assertSame('101', $matches['901']->storedKey);
        self::assertTrue($matches['901']->replacesUnimportedItem);
    }

    public function testAnItemWhoseEventWasDeletedStaysUnderItsKey(): void
    {
        self::assertSame([], PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05'), '901' => self::stored('901', null, null, imported: false, detached: true)],
        ));
    }

    public function testTwoImportedEventsWithItsDateAndNameAreAProblem(): void
    {
        $matches = PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-05 19:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05'), '102' => self::stored('102', 'maple night', '2026-10-05')],
        );

        self::assertTrue($matches['901']->isProblem());
        self::assertSame(PreviousSheetMatch::MATCHES_SEVERAL_IMPORTED_EVENTS, $matches['901']->problem);
        self::assertSame('2', $matches['901']->detail);
        self::assertNull($matches['901']->storedKey);
    }

    public function testTwoEventsOfTheSheetMatchingOneImportedEventAreBothAProblem(): void
    {
        $matches = PreviousSheetEvents::match(
            [self::event('901', 'Maple Night', '2026-10-05 19:00'), self::event('902', 'Maple Night', '2026-10-05 20:00')],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05')],
        );

        self::assertSame(PreviousSheetMatch::SHARES_IMPORTED_EVENT, $matches['901']->problem);
        self::assertSame(PreviousSheetMatch::SHARES_IMPORTED_EVENT, $matches['902']->problem);
    }

    public function testAnEventWithoutNameOrDateIsNotMatched(): void
    {
        self::assertSame([], PreviousSheetEvents::match(
            [self::event('901', null, '2026-10-05 19:00'), self::event('902', 'Maple Night', null)],
            ['101' => self::stored('101', 'Maple Night', '2026-10-05')],
        ));
    }

    private static function event(string $key, null|string $name, null|string $startsAt): ParsedEvent
    {
        return new ParsedEvent(
            key: $key,
            title: 'Tab ' . $key,
            eventName: $name,
            location: 'Hall',
            cityAndState: 'Springfield, MN',
            localStartsAt: $startsAt !== null ? new DateTimeImmutable($startsAt) : null,
            startTimeKnown: true,
            timeLimitMinutes: 90,
            category: RoundCategory::Team,
            puzzleId: null,
            rows: [],
            problems: [],
            contentHash: sha1($key),
        );
    }

    private static function stored(string $key, null|string $name, null|string $date, bool $imported = true, bool $detached = false): StoredResultsTab
    {
        return new StoredResultsTab(
            key: $key,
            status: match (true) {
                $imported => EventTabStatus::Imported,
                $detached => EventTabStatus::Detached,
                default => EventTabStatus::WaitingForPuzzle,
            },
            contentHash: sha1('stored' . $key),
            isDetached: $detached,
            appliedAt: new DateTimeImmutable('2026-11-01 10:00:00'),
            competitionId: $imported ? '0196f5a0-0000-7000-8000-0000000000' . substr('00' . $key, -2) : null,
            competitionName: $imported ? $name : null,
            competitionSlug: null,
            location: null,
            dateFrom: $imported && $date !== null ? new DateTimeImmutable($date) : null,
            roundId: null,
            roundSlug: null,
            roundCategory: null,
            minutesLimit: null,
            roundStartsAt: null,
            startTimeKnown: true,
            roundTimezone: null,
            roundPuzzleIds: [],
            results: [],
        );
    }
}
