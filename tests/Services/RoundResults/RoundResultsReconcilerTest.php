<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\RoundResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoundResultsReconcilerTest extends KernelTestCase
{
    private Connection $database;
    private RoundResultsReconciler $reconciler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->reconciler = self::getContainer()->get(RoundResultsReconciler::class);
    }

    public function testFixtureLinksAlreadyFollowTheRule(): void
    {
        self::assertSame(['linked' => 0, 'unlinked' => 0], $this->reconciler->reconcile());
    }

    public function testLinksTimesByCompetitionPuzzleAndCategory(): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET competition_round_id = NULL');

        $result = $this->reconciler->reconcile();

        // TIME_09-11 are on the qualification puzzle, TIME_19-20 on a final round puzzle, EventDetailFixture::TIME_SPRINT_1
        // on Sprint 1's
        self::assertSame(6, $result['linked']);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_FINAL, $this->roundOf(PuzzleSolvingTimeFixture::TIME_19));
    }

    public function testRelinksTimeThatPointsAtTheWrongRound(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = :round WHERE id = :id',
            ['round' => CompetitionRoundFixture::ROUND_WJPC_FINAL, 'id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        $this->reconciler->reconcile(Uuid::fromString(CompetitionFixture::COMPETITION_WJPC_2024));

        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    public function testCategoryMustMatch(): void
    {
        // The qualification round is solo - a duo time on its puzzle is not its result
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET puzzling_type = 'duo' WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        $result = $this->reconciler->reconcile();

        self::assertSame(1, $result['unlinked']);
        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    public function testUnlinksTimeWhoseCompetitionWasRemoved(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        // Scoped to the competition the time was linked through, even though the time left it
        $this->reconciler->reconcile(Uuid::fromString(CompetitionFixture::COMPETITION_WJPC_2024));

        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    public function testScopedReconcileLeavesOtherCompetitionsAlone(): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET competition_round_id = NULL');

        $result = $this->reconciler->reconcile(Uuid::fromString(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));

        self::assertSame(0, $result['linked']);
        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    /**
     * The round results of a series' editions (docs/features/events-page/high-frequency-series.md): every edition's
     * times, the series' picks wherever they point, and times linked to a round of one of its editions - nothing of
     * another series or competition.
     */
    public function testReconcilesTheEditionsOfOneSeries(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $explicit = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $edition);
        $pick = $scenario->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, $puzzle, '2026-03-02', seriesId: $series);
        self::assertSame($round, $this->roundOf($explicit));
        self::assertSame($round, $this->roundOf($pick));

        $this->database->executeStatement('UPDATE puzzle_solving_time SET competition_round_id = NULL');

        $result = $this->reconciler->reconcileSeries(Uuid::fromString($series));

        self::assertSame(['linked' => 2, 'unlinked' => 0], $result);
        self::assertSame($round, $this->roundOf($explicit));
        self::assertSame($round, $this->roundOf($pick));
        // Another competition's times are left alone
        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));

        // A series pick that left the edition (series-level now) still points at its round: unlinked by the series scope
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL, series_edition_match = NULL, competition_round_id = :round WHERE id = :id',
            ['round' => $round, 'id' => $pick],
        );

        self::assertSame(['linked' => 0, 'unlinked' => 1], $this->reconciler->reconcileSeries(Uuid::fromString($series)));
        self::assertNull($this->roundOf($pick));
    }

    private function roundOf(string $timeId): null|string
    {
        $round = $this->database->fetchOne('SELECT competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return is_string($round) ? $round : null;
    }
}
