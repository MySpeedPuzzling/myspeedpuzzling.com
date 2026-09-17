<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\RoundResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
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

        // TIME_09-11 are on the qualification puzzle, TIME_19-20 on a final round puzzle
        self::assertSame(5, $result['linked']);
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

    private function roundOf(string $timeId): null|string
    {
        $round = $this->database->fetchOne('SELECT competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return is_string($round) ? $round : null;
    }
}
