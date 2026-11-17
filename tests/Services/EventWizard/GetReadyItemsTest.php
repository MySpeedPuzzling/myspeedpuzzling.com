<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventWizard;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRoundFacts;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\EventDetailsItem;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\EventGetReadyChecklist;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\JudgesItem;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\NameTagsItem;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\RegistrationItem;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\RoundsAndPuzzlesItem;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\StopwatchTestRunTip;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\TableNumbersItem;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\TimerScreenTip;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;

/**
 * Each "Get ready" item's rules (W2, W-creation-wizard-research.md §3.10): when its row exists, when it is done, where
 * it links - and when the whole checklist is gone (the event is over).
 */
final class GetReadyItemsTest extends TestCase
{
    private const string EVENT = '018d0000-0000-0000-0000-0000000000aa';
    private const string NOW = '2026-10-14 12:00:00';

    public function testEventDetailsAreAlwaysDone(): void
    {
        self::assertTrue((new EventDetailsItem())->row(self::facts())->done);
    }

    public function testRoundsAndPuzzles(): void
    {
        $item = new RoundsAndPuzzlesItem();

        $none = $item->row(self::facts());
        self::assertFalse($none->done);
        self::assertSame('manage_competition_rounds', $none->route);
        self::assertSame(['competitionId' => self::EVENT], $none->routeParameters);

        // "Decide later": that round's puzzles
        $later = $item->row(self::facts(rounds: [self::round('r1', puzzles: 1), self::round('r2', puzzles: 0)]));
        self::assertFalse($later->done);
        self::assertSame('manage_round_puzzles', $later->route);
        self::assertSame(['roundId' => 'r2'], $later->routeParameters);
        self::assertSame('event_wizard.get_ready.named_round_without_puzzle', $later->noteKey);
        self::assertSame(['%count%' => 2], $later->labelParameters);

        $single = $item->row(self::facts(rounds: [self::round('r1', puzzles: 0)]));
        self::assertSame('event_wizard.get_ready.round_without_puzzle', $single->noteKey);

        $done = $item->row(self::facts(rounds: [self::round('r1', puzzles: 1), self::round('r2', puzzles: 3)]));
        self::assertTrue($done->done);
        self::assertNull($done->route);
    }

    public function testJudgesAreDoneOnceAddedElseASuggestion(): void
    {
        $item = new JudgesItem();

        $suggestion = $item->row(self::facts());
        self::assertNull($suggestion->done);
        self::assertSame('competition_referees', $suggestion->route);

        $added = $item->row(self::facts(referees: 3));
        self::assertTrue($added->done);
        self::assertSame(['%count%' => 3], $added->labelParameters);
    }

    public function testRegistrationOnlyWhileManaged(): void
    {
        $item = new RegistrationItem();
        self::assertNull($item->row(self::facts()));

        $capacity = $item->row(self::facts(registrationManaged: true, capacity: 80, going: 12));
        self::assertNotNull($capacity);
        self::assertTrue($capacity->done);
        self::assertSame('event_wizard.get_ready.places_taken', $capacity->noteKey);
        self::assertSame(['%taken%' => 12, '%capacity%' => 80], $capacity->noteParameters);

        $open = $item->row(self::facts(registrationManaged: true, going: 4));
        self::assertNotNull($open);
        self::assertSame('event_wizard.get_ready.registered', $open->noteKey);
    }

    public function testTableNumbersOnceEntriesExistInRoundsUsingTables(): void
    {
        $item = new TableNumbersItem();

        // No entries yet, tables off, online: no row
        self::assertNull($item->row(self::facts(rounds: [self::round('r1')])));
        self::assertNull($item->row(self::facts(rounds: [self::round('r1', entries: 5, tablesOff: true)])));
        self::assertNull($item->row(self::facts(online: true, rounds: [self::round('r1', entries: 5)])));

        $missing = $item->row(self::facts(rounds: [self::round('r1', entries: 4, withTable: 4), self::round('r2', entries: 6, withTable: 2), self::round('r3', entries: 3, tablesOff: true)]));
        self::assertNotNull($missing);
        self::assertFalse($missing->done);
        self::assertSame(['roundId' => 'r2'], $missing->routeParameters);
        self::assertSame(['%assigned%' => 6, '%total%' => 10], $missing->noteParameters);

        $seated = $item->row(self::facts(rounds: [self::round('r1', entries: 4, withTable: 4)]));
        self::assertNotNull($seated);
        self::assertTrue($seated->done);
        self::assertSame(['roundId' => 'r1'], $seated->routeParameters);
    }

    public function testNameTagsOnceSomebodyIsGoing(): void
    {
        $item = new NameTagsItem();

        self::assertNull($item->row(self::facts()));
        self::assertNull($item->row(self::facts(going: 2))?->done);
        self::assertSame('competition_name_tags', $item->row(self::facts(rounds: [self::round('r1', entries: 1)]))?->route);
    }

    public function testToolkitTipsOnlyForWhomTheToolkitIsOn(): void
    {
        $screen = new TimerScreenTip();
        self::assertNull($screen->row(self::facts(screens: 1)));
        self::assertNull($screen->row(self::facts(toolkit: true)));
        self::assertSame('competition_displays', $screen->row(self::facts(toolkit: true, screens: 1))?->route);

        $stopwatch = new StopwatchTestRunTip();
        $secret = [self::round('r1', puzzles: 1), self::round('r2', puzzles: 1, secret: 1)];
        self::assertNull($stopwatch->row(self::facts(rounds: $secret)));
        self::assertNull($stopwatch->row(self::facts(toolkit: true, rounds: [self::round('r1', puzzles: 1)])));
        $tip = $stopwatch->row(self::facts(toolkit: true, rounds: $secret));
        self::assertNotNull($tip);
        self::assertNull($tip->done);
        self::assertSame('manage_round_stopwatch', $tip->route);
        self::assertSame(['roundId' => 'r2'], $tip->routeParameters);
    }

    public function testTheChecklistIsGoneOnceTheEventIsOver(): void
    {
        // Without rounds: after its last day
        self::assertFalse(EventGetReadyChecklist::over(self::facts()));
        self::assertFalse(EventGetReadyChecklist::over(self::facts(dateFrom: '2026-10-14')));
        self::assertTrue(EventGetReadyChecklist::over(self::facts(dateFrom: '2026-10-13')));
        self::assertFalse(EventGetReadyChecklist::over(self::facts(dateFrom: '2026-10-12', dateTo: '2026-10-14')));

        // Rounds: every one stopped or past its start + limit
        $past = self::round('r1', startsAt: '2026-10-14 10:00:00', limit: 60);
        $running = self::round('r2', startsAt: '2026-10-14 11:30:00', limit: 60);
        $stopped = self::round('r3', startsAt: '2026-10-14 11:30:00', limit: 60, stopwatch: RoundStopwatchStatus::STOPPED);
        self::assertTrue(EventGetReadyChecklist::over(self::facts(rounds: [$past, $stopped])));
        self::assertFalse(EventGetReadyChecklist::over(self::facts(rounds: [$past, $running])));
        // No limit: three hours
        self::assertFalse(EventGetReadyChecklist::over(self::facts(rounds: [self::round('r4', startsAt: '2026-10-14 09:30:00', limit: null)])));
        self::assertTrue(EventGetReadyChecklist::over(self::facts(rounds: [self::round('r4', startsAt: '2026-10-14 08:30:00', limit: null)])));
        // A stopwatch started late counts from its start
        $late = self::round('r5', startsAt: '2026-10-14 10:00:00', limit: 60, stopwatch: RoundStopwatchStatus::RUNNING, startedAt: '2026-10-14 11:15:00');
        self::assertFalse(EventGetReadyChecklist::over(self::facts(rounds: [$late])));
    }

    /**
     * @param list<GetReadyRoundFacts> $rounds
     */
    private static function facts(
        array $rounds = [],
        bool $online = false,
        bool $registrationManaged = false,
        null|int $capacity = null,
        int $going = 0,
        int $referees = 0,
        int $screens = 0,
        bool $toolkit = false,
        null|string $dateFrom = null,
        null|string $dateTo = null,
    ): GetReadyFacts {
        return new GetReadyFacts(
            competitionId: self::EVENT,
            isDraft: false,
            isOnline: $online,
            dateFrom: $dateFrom !== null ? new DateTimeImmutable($dateFrom) : null,
            dateTo: $dateTo !== null ? new DateTimeImmutable($dateTo) : null,
            registrationManaged: $registrationManaged,
            capacity: $capacity,
            participantsGoing: $going,
            refereesCount: $referees,
            screenDisplaysCount: $screens,
            rounds: $rounds,
            toolkitAvailable: $toolkit,
            now: new DateTimeImmutable(self::NOW),
        );
    }

    private static function round(
        string $id,
        int $puzzles = 0,
        int $secret = 0,
        int $entries = 0,
        int $withTable = 0,
        bool $tablesOff = false,
        string $startsAt = '2026-11-01 10:00:00',
        null|int $limit = 90,
        null|string $stopwatch = null,
        null|string $startedAt = null,
    ): GetReadyRoundFacts {
        return new GetReadyRoundFacts(
            roundId: $id,
            name: 'Round ' . $id,
            startsAt: new DateTimeImmutable($startsAt),
            minutesLimit: $limit,
            stopwatchStatus: $stopwatch,
            stopwatchStartedAt: $startedAt !== null ? new DateTimeImmutable($startedAt) : null,
            tableNumbersOff: $tablesOff,
            puzzlesCount: $puzzles,
            secretPuzzlesCount: $secret,
            entriesTotal: $entries,
            entriesWithTableNumber: $withTable,
        );
    }
}
