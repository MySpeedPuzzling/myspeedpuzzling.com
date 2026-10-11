<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\BadgeConditions\PuzzlesSolvedCondition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\DateAchievementsFromHistory;
use SpeedPuzzling\Web\Results\AchievementDatingOutcome;
use SpeedPuzzling\Web\Value\BadgeTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Real dates for the tiers the launch backfill stamped (docs/features/badges.md "Real dates"): only the tiers inside
 * the backfill's window (the fixtures: 2026-08-01 .. 2026-08-02, XpSettingsFixture), only to an earlier date, the dry
 * run changes nothing, and a dated tier is out of the window for good.
 */
final class DateAchievementsFromHistoryHandlerTest extends KernelTestCase
{
    private Connection $database;

    private MessageBusInterface $bus;

    private string $playerId;

    private int $bronzePuzzles;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->bronzePuzzles = self::getContainer()->get(PuzzlesSolvedCondition::class)->requirementForTier(BadgeTier::Bronze);
        $this->playerId = $this->playerWithBronzePuzzlesSolvedIn2024();
    }

    public function testTheStampedTiersGetTheDateTheirRecordsFirstMetThem(): void
    {

        $stamped = $this->badge('puzzles_solved', 1, '2026-08-01 12:00:00');
        // Earned after the launch: its date is real already
        $afterLaunch = $this->badge('pieces_solved', 1, '2026-09-10 08:00:00');
        // Early Adopter keeps its grant date
        $single = $this->badge('supporter', null, '2026-08-01 12:00:00');

        $dryRun = $this->date(dryRun: true);
        self::assertSame(1, $dryRun->dated);
        self::assertSame('2026-08-01 12:00:00', $this->earnedAt($stamped), 'The dry run changes nothing');

        $outcome = $this->date(dryRun: false);
        self::assertSame(1, $outcome->dated);
        self::assertSame(0, $outcome->undated);
        self::assertTrue($outcome->replayMatched);
        self::assertSame(sprintf('2024-01-%02d 10:00:00', $this->bronzePuzzles), $this->earnedAt($stamped), 'The day of the puzzle that made the count');
        self::assertSame('2026-09-10 08:00:00', $this->earnedAt($afterLaunch));
        self::assertSame('2026-08-01 12:00:00', $this->earnedAt($single));

        // Out of the window for good: a second run has nothing to do
        $again = $this->date(dryRun: false);
        self::assertSame(0, $again->dated);
        self::assertSame(0, $again->undated);
    }

    public function testATierTheRecordsDoNotMeetKeepsItsStamp(): void
    {
        // The records do not hold a Diamond of Steady Hands: no date to give
        $stamped = $this->badge('steady_hands', 5, '2026-08-01 12:00:00');

        $outcome = $this->date(dryRun: false);

        self::assertSame(0, $outcome->dated);
        self::assertSame(1, $outcome->undated);
        self::assertSame('2026-08-01 12:00:00', $this->earnedAt($stamped));
    }

    private function date(bool $dryRun): AchievementDatingOutcome
    {
        $envelope = $this->bus->dispatch(new DateAchievementsFromHistory($this->playerId, $dryRun), [new TransportNamesStamp(['sync'])]);
        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(AchievementDatingOutcome::class, $outcome);

        return $outcome;
    }

    private function badge(string $type, null|int $tier, string $earnedAt): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :player, :type, :earnedAt, :tier)',
            ['id' => $id, 'player' => $this->playerId, 'type' => $type, 'earnedAt' => $earnedAt, 'tier' => $tier],
        );

        return $id;
    }

    private function playerWithBronzePuzzlesSolvedIn2024(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'dated' . substr(Uuid::uuid4()->toString(), 0, 8),
            userId: null,
            name: 'Dated tester',
            registeredAt: new DateTimeImmutable('2023-12-01 10:00'),
        );
        $entityManager->persist($player);
        $entityManager->flush();

        /** @var list<string> $puzzles */
        $puzzles = $this->database->fetchFirstColumn('SELECT CAST(id AS TEXT) FROM puzzle WHERE pieces_count = 500 ORDER BY id LIMIT ' . $this->bronzePuzzles);

        foreach ($puzzles as $index => $puzzleId) {
            $this->database->executeStatement(
                <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type)
VALUES (:id, :playerId, :puzzleId, 9000, '2026-06-01 12:00:00', :finishedAt, true, true, 'solo')
SQL,
                ['id' => Uuid::uuid7()->toString(), 'playerId' => $player->id->toString(), 'puzzleId' => $puzzleId, 'finishedAt' => sprintf('2024-01-%02d 10:00:00', $index + 1)],
            );
        }

        return $player->id->toString();
    }

    private function earnedAt(string $badgeId): string
    {
        $value = $this->database->fetchOne('SELECT earned_at FROM badge WHERE id = :id', ['id' => $badgeId]);
        self::assertIsString($value);

        return $value;
    }
}
