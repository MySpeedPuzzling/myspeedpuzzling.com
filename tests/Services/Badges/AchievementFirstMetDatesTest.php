<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Badges;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\BadgeConditions\PuzzlesSolvedCondition;
use SpeedPuzzling\Web\BadgeConditions\Speed500PiecesCondition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Services\Badges\AchievementFirstMetDates;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The history replay behind the real achievement dates (docs/features/badges.md "Real dates"): it must rebuild exactly
 * the metrics the badge evaluator reads (GetPlayerStatsSnapshot) - for every fixture player, whose results cover solo,
 * pairs, teams, relax, first tries, brands and big puzzles - and date a tier at the result that met it.
 */
final class AchievementFirstMetDatesTest extends KernelTestCase
{
    private AchievementFirstMetDates $dates;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dates = self::getContainer()->get(AchievementFirstMetDates::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheReplayEndsAtTheCurrentRecordsOfEveryFixturePlayer(): void
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn('SELECT CAST(id AS TEXT) FROM player ORDER BY id');
        self::assertNotEmpty($playerIds);

        foreach ($playerIds as $playerId) {
            self::assertNotNull($this->dates->forPlayer($playerId), 'The replay of ' . $playerId . ' does not end at their snapshot');
        }
    }

    public function testATierIsDatedAtTheResultThatMetIt(): void
    {
        $playerId = $this->player();
        $bronzePuzzles = self::getContainer()->get(PuzzlesSolvedCondition::class)->requirementForTier(BadgeTier::Bronze);
        $bronzeSeconds = self::getContainer()->get(Speed500PiecesCondition::class)->requirementForTier(BadgeTier::Bronze);

        /** @var list<string> $puzzles */
        $puzzles = $this->database->fetchFirstColumn(
            'SELECT CAST(id AS TEXT) FROM puzzle WHERE pieces_count = 500 ORDER BY id LIMIT ' . $bronzePuzzles,
        );
        self::assertCount($bronzePuzzles, $puzzles);

        // All logged in 2026, solved on consecutive days of 2024 - the third one fast enough for the Bronze speed, the
        // fourth even faster but with a finished_at after its logging (a typo: the logging time counts)
        foreach ($puzzles as $index => $puzzleId) {
            $day = sprintf('2024-01-%02d', $index + 1);

            match ($index) {
                2 => $this->solve($playerId, $puzzleId, $bronzeSeconds - 60, $day . ' 10:00:00'),
                3 => $this->solve($playerId, $puzzleId, $bronzeSeconds - 120, '2030-05-05 10:00:00', trackedAt: $day . ' 09:00:00'),
                default => $this->solve($playerId, $puzzleId, $bronzeSeconds + 600, $day . ' 10:00:00'),
            };
        }

        $dates = $this->dates->forPlayer($playerId);

        self::assertNotNull($dates);
        self::assertSame(
            sprintf('2024-01-%02d 10:00:00', $bronzePuzzles),
            $dates[BadgeType::PuzzlesSolved->value][BadgeTier::Bronze->value]->format('Y-m-d H:i:s'),
            'The day of the puzzle that made the count',
        );
        self::assertSame('2024-01-03 10:00:00', $dates[BadgeType::Speed500Pieces->value][BadgeTier::Bronze->value]->format('Y-m-d H:i:s'), 'The first time fast enough');
        self::assertArrayNotHasKey(BadgeTier::Silver->value, $dates[BadgeType::PuzzlesSolved->value]);
    }

    private function player(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'dating' . substr(Uuid::uuid4()->toString(), 0, 8),
            userId: null,
            name: 'Dating tester',
            registeredAt: new DateTimeImmutable('2023-12-01 10:00'),
        );
        $entityManager->persist($player);
        $entityManager->flush();

        return $player->id->toString();
    }

    private function solve(string $playerId, string $puzzleId, int $seconds, string $finishedAt, string $trackedAt = '2026-06-01 12:00:00'): void
    {
        $this->database->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type)
VALUES (:id, :playerId, :puzzleId, :seconds, :trackedAt, :finishedAt, true, true, 'solo')
SQL,
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'puzzleId' => $puzzleId, 'seconds' => $seconds, 'trackedAt' => $trackedAt, 'finishedAt' => $finishedAt],
        );
    }
}
