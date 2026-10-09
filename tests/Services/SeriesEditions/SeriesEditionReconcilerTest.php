<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SeriesEditions;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Message\ApproveCompetitionSeries;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionReconciler;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bulk reconcile of series picks (docs/features/events-page/high-frequency-series.md "Stickiness" and the
 * scenario matrix H12). The stickiness rows change the editions in SQL - nothing records an event then, so the
 * reconcile called here is the only one that runs; the scenarios go through the messages and their triggers.
 */
final class SeriesEditionReconcilerTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID;

    private SeriesEditionScenario $scenario;
    private SeriesEditionReconciler $reconciler;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->reconciler = self::getContainer()->get(SeriesEditionReconciler::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testNothingToDoOnTheFixtures(): void
    {
        self::assertSame(
            ['linked' => 0, 'moved' => 0, 'released' => 0, 'roundsLinked' => 0, 'roundsUnlinked' => 0],
            $this->reconciler->reconcile(),
        );
    }

    /**
     * Stickiness: series-level + the rule finds an edition -> linked; finds nothing -> left alone. H12 3: editions
     * with dates only match by the day.
     */
    public function testSeriesLevelIsLinkedWhenTheRuleFindsAnEditionAndLeftAloneOtherwise(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-04-01');
        $onTheDay = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        $elsewhere = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-05-20', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($onTheDay));

        // The edition moves onto the solve day in SQL - no event, only this reconcile
        $this->database->executeStatement("UPDATE competition SET date_from = '2026-03-02', date_to = '2026-03-02' WHERE id = :id", ['id' => $edition]);

        $counts = $this->reconciler->reconcile(Uuid::fromString($series));

        self::assertSame(['linked' => 1, 'moved' => 0, 'released' => 0], self::seriesCounts($counts));
        self::assertSame([$edition, 'date'], $this->editionOf($onTheDay));
        self::assertSame([null, null], $this->editionOf($elsewhere));
        self::assertSame($series, $this->scenario->link($elsewhere)['competition_series_id']);
    }

    /**
     * Stickiness: a puzzle link that still holds is kept - also when another edition became nearer.
     */
    public function testAPuzzleLinkThatHoldsIsKeptEvenWhenAnotherEditionIsNearer(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $this->scenario->round($first, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-20', seriesId: $series);
        self::assertSame([$first, 'puzzle'], $this->editionOf($time));

        // The puzzle is used again on the solve day - a fresh save would match it there
        $repeat = $this->scenario->edition($series, 'Jam No. 9', '2026-03-20');
        $this->scenario->round($repeat, RoundCategory::Solo, '2026-03-20 19:00', puzzleIds: [$puzzle]);
        $fresh = self::getContainer()->get(SeriesEditionResolver::class)->preview($series, $puzzle, new DateTimeImmutable('2026-03-20'), PuzzlingType::Solo);
        self::assertSame($repeat, $fresh->competitionId);

        self::assertSame([$first, 'puzzle'], $this->editionOf($time));
        self::assertSame(['linked' => 0, 'moved' => 0, 'released' => 0], self::seriesCounts($this->reconciler->reconcile(Uuid::fromString($series))));
    }

    /**
     * Stickiness: a date link that still holds is kept while the rule finds no puzzle match - even when the date alone
     * would not decide any more (a second edition next to it).
     */
    public function testADateLinkThatHoldsIsKept(): void
    {
        $series = $this->scenario->series();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([$first, 'date'], $this->editionOf($time));

        $this->scenario->edition($series, 'Jam No. 2', '2026-03-03');

        self::assertSame([$first, 'date'], $this->editionOf($time));
        self::assertSame(['linked' => 0, 'moved' => 0, 'released' => 0], self::seriesCounts($this->reconciler->reconcile(Uuid::fromString($series))));
    }

    /**
     * Stickiness: puzzle beats date - a date link moves when the rule finds a puzzle match, on its edition (H12 4, the
     * puzzle attached) or on another one.
     */
    public function testADateLinkYieldsToAPuzzleMatch(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $sameDay = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $round = $this->scenario->round($sameDay, RoundCategory::Solo, '2026-03-02 19:00');
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-02', seriesId: $series);
        self::assertSame([$sameDay, 'date'], $this->editionOf($time));
        self::assertNull($this->scenario->link($time)['competition_round_id']);

        // H12 4: the puzzle is attached to the round in SQL - the reconcile alone upgrades the link
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, reveal_mode, hides_everywhere) VALUES (:id, :round, :puzzle, false, :mode, false)',
            ['id' => Uuid::uuid7()->toString(), 'round' => $round, 'puzzle' => $puzzle, 'mode' => 'automatic'],
        );

        $counts = $this->reconciler->reconcile(Uuid::fromString($series));

        self::assertSame(['linked' => 0, 'moved' => 1, 'released' => 0], self::seriesCounts($counts));
        self::assertSame(1, $counts['roundsLinked']);
        self::assertSame([$sameDay, 'puzzle'], $this->editionOf($time));
        self::assertSame($round, $this->scenario->link($time)['competition_round_id']);

        // On another edition: the date link moves there
        $other = $this->scenario->puzzle('Starry Harbor');
        $dated = $this->scenario->addTime(self::OTHER_PLAYER, $other, '2026-03-02', seriesId: $series);
        self::assertSame([$sameDay, 'date'], $this->editionOf($dated));
        $later = $this->scenario->edition($series, 'Jam No. 5', '2026-03-12');
        $this->scenario->round($later, RoundCategory::Solo, '2026-03-12 19:00', puzzleIds: [$other]);

        self::assertSame([$later, 'puzzle'], $this->editionOf($dated));
    }

    /**
     * Stickiness: an automatic link that no longer holds takes the rule's current answer - another edition, or back to
     * series-level; its round goes with it and the new edition's round is linked (H12 2, H12 14: the round results
     * follow the matched edition).
     */
    public function testALinkThatNoLongerHoldsTakesTheCurrentAnswer(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $second = $this->scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $firstRound = $this->scenario->round($first, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $secondRound = $this->scenario->round($second, RoundCategory::Solo, '2026-03-20 19:00');
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-19', seriesId: $series);
        self::assertSame([$first, 'puzzle'], $this->editionOf($time));
        self::assertSame($firstRound, $this->scenario->link($time)['competition_round_id']);

        // The puzzle moves to the second edition's round, in SQL
        $this->database->executeStatement('UPDATE competition_round_puzzle SET round_id = :round WHERE round_id = :old', ['round' => $secondRound, 'old' => $firstRound]);

        $counts = $this->reconciler->reconcile(Uuid::fromString($series));

        self::assertSame(['linked' => 0, 'moved' => 1, 'released' => 0], self::seriesCounts($counts));
        self::assertSame([$second, 'puzzle'], $this->editionOf($time));
        self::assertSame($secondRound, $this->scenario->link($time)['competition_round_id']);
        self::assertSame(1, $counts['roundsLinked']);

        // The round leaves the puzzle and the edition moves away - nothing else matches: series-level, without a round
        $this->database->executeStatement('DELETE FROM competition_round_puzzle WHERE round_id = :round', ['round' => $secondRound]);
        $this->database->executeStatement("UPDATE competition_round SET starts_at = '2026-06-01 17:00:00' WHERE id = :round", ['round' => $secondRound]);
        $this->database->executeStatement("UPDATE competition SET date_from = '2026-06-01', date_to = '2026-06-01' WHERE id = :id", ['id' => $second]);

        $counts = $this->reconciler->reconcile(Uuid::fromString($series));

        self::assertSame(['linked' => 0, 'moved' => 0, 'released' => 1], self::seriesCounts($counts));
        self::assertSame([null, null, null], [...$this->editionOf($time), $this->scenario->link($time)['competition_round_id']]);
        self::assertSame($series, $this->scenario->link($time)['competition_series_id']);
    }

    /**
     * Stickiness: an explicit link is never touched, whatever happens to the editions.
     */
    public function testAnExplicitLinkIsNeverTouched(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $other = $this->scenario->edition($series, 'Jam No. 2', '2026-07-02');
        $explicit = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-07-02', competitionId: $edition);

        $this->reconciler->reconcile(Uuid::fromString($series));
        $this->reconciler->reconcile();

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($explicit),
        );
        self::assertNotSame($other, $this->scenario->link($explicit)['competition_id']);
    }

    /**
     * A scoped reconcile leaves the other series' picks alone; the global one takes every series.
     */
    public function testAScopedReconcileLeavesOtherSeriesAlone(): void
    {
        $series = $this->scenario->series();
        $otherSeries = $this->scenario->series('Moonlit Puzzle Sprint');
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-04-01');
        $otherEdition = $this->scenario->edition($otherSeries, 'Sprint No. 1', '2026-04-01');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        $otherTime = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $otherSeries);
        $this->database->executeStatement("UPDATE competition SET date_from = '2026-03-02', date_to = '2026-03-02' WHERE id IN (:a, :b)", ['a' => $edition, 'b' => $otherEdition]);

        $this->reconciler->reconcile(Uuid::fromString($series));
        self::assertSame([$edition, 'date'], $this->editionOf($time));
        self::assertSame([null, null], $this->editionOf($otherTime));

        $counts = $this->reconciler->reconcile();
        self::assertSame(1, $counts['linked']);
        self::assertSame([$otherEdition, 'date'], $this->editionOf($otherTime));
    }

    /**
     * H12 4: reveal now records an event - the date match becomes a puzzle match at once; a reveal by time records
     * nothing - the 15-minute global reconcile upgrades it.
     */
    public function testScenario4RevealedPuzzlesUpgradeDateMatches(): void
    {
        $series = $this->scenario->series();
        $secret = $this->scenario->puzzle('Copper Lighthouse');
        $timed = $this->scenario->puzzle('Starry Harbor');
        $edition = $this->scenario->edition($series, 'Jam No. 155', '2026-03-05');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-05 19:00', puzzleIds: [$secret, $timed], secret: true);
        $revealedNow = $this->scenario->addTime(self::PLAYER, $secret, '2026-03-05', seriesId: $series);
        $revealedByTime = $this->scenario->addTime(self::OTHER_PLAYER, $timed, '2026-03-05', seriesId: $series);
        self::assertSame([$edition, 'date'], $this->editionOf($revealedNow));
        self::assertSame([$edition, 'date'], $this->editionOf($revealedByTime));

        $this->scenario->dispatch(new RevealRoundPuzzleNow($this->scenario->roundPuzzleId($round, $secret)));
        self::assertSame([$edition, 'puzzle'], $this->editionOf($revealedNow));
        self::assertSame([$edition, 'date'], $this->editionOf($revealedByTime));

        // Its scheduled moment passed - no event, nothing changes until the cron
        $this->database->executeStatement(
            "UPDATE competition_round_puzzle SET reveal_mode = 'scheduled', reveal_at = NOW() - INTERVAL '1 hour' WHERE id = :id",
            ['id' => $this->scenario->roundPuzzleId($round, $timed)],
        );
        self::assertSame([$edition, 'date'], $this->editionOf($revealedByTime));

        $this->scenario->reconcile();
        self::assertSame([$edition, 'puzzle'], $this->editionOf($revealedByTime));
        self::assertSame($round, $this->scenario->link($revealedByTime)['competition_round_id']);
    }

    /**
     * H12 6: a series without editions keeps its times series-level - until its first edition (H13).
     */
    public function testScenario6TheFirstEditionMatchesTheSeriesLevelTimes(): void
    {
        $series = $this->scenario->series();
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($time));
        self::assertSame($series, $this->scenario->link($time)['competition_series_id']);

        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');

        self::assertSame([$first, 'date'], $this->editionOf($time));
    }

    /**
     * H12 8: two editions on the same day - series-level; a later attach of the puzzle resolves it.
     */
    public function testScenario8ALaterAttachResolvesTwoEditionsOnTheSameDay(): void
    {
        $series = $this->scenario->series();
        $flex = $this->scenario->edition($series, 'Flex Jam', '2026-03-11');
        $live = $this->scenario->edition($series, 'Live Jam', '2026-03-11');
        $this->scenario->round($flex, RoundCategory::Solo, '2026-03-11 10:00');
        $liveRound = $this->scenario->round($live, RoundCategory::Solo, '2026-03-11 19:00');
        $puzzle = $this->scenario->puzzle();
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-11', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new SetCompetitionRoundPuzzles($liveRound, [$puzzle]));

        self::assertSame([$live, 'puzzle'], $this->editionOf($time));
        self::assertSame($liveRound, $this->scenario->link($time)['competition_round_id']);
    }

    /**
     * H12 9: a draft edition is matched once published; the editions of a pending series once it is approved.
     */
    public function testScenario9PublishingAndApprovalMatch(): void
    {
        $series = $this->scenario->series();
        $draft = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02', draft: true);
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new PublishCompetition($draft, notifyAdmin: false));
        self::assertSame([$draft, 'date'], $this->editionOf($time));

        // A series waiting for approval (set in SQL - an approved series never goes back to pending through the app)
        $pending = $this->scenario->series('Harbor Puzzle Club Nights');
        $pendingTime = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-03-09', seriesId: $pending);
        $this->database->executeStatement('UPDATE competition_series SET approved_at = NULL, approved_by_player_id = NULL WHERE id = :id', ['id' => $pending]);
        $night = $this->scenario->edition($pending, 'Night No. 1', '2026-03-09');
        self::assertSame([null, null], $this->editionOf($pendingTime));

        $this->scenario->dispatch(new ApproveCompetitionSeries($pending, PlayerFixture::PLAYER_ADMIN, notifyCreator: false));
        self::assertSame([$night, 'date'], $this->editionOf($pendingTime));
    }

    /**
     * P6 + the scoped round reconcile: an edition added with only its last day matches the day after it too.
     */
    public function testAnEditionAddedWithItsLastDayOnlyMatches(): void
    {
        $series = $this->scenario->series();
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-11', seriesId: $series);
        $editionId = Uuid::uuid7();

        $this->scenario->dispatch(new AddEdition(
            competitionId: $editionId,
            seriesId: $series,
            name: 'Jam No. 10',
            dateFrom: null,
            dateTo: new DateTimeImmutable('2026-03-10'),
            registrationLink: null,
            resultsLink: null,
        ));

        self::assertSame([$editionId->toString(), 'date'], $this->editionOf($time));
    }

    /**
     * @return array{?string, ?string} competition_id, series_edition_match
     */
    private function editionOf(string $timeId): array
    {
        $link = $this->scenario->link($timeId);

        return [$link['competition_id'], $link['series_edition_match']];
    }

    /**
     * @param array{linked: int, moved: int, released: int, roundsLinked: int, roundsUnlinked: int} $counts
     * @return array{linked: int, moved: int, released: int}
     */
    private static function seriesCounts(array $counts): array
    {
        return ['linked' => $counts['linked'], 'moved' => $counts['moved'], 'released' => $counts['released']];
    }
}
