<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetAccountDeletionSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "this is what goes with it" numbers on the last-chance page. Seeded on a
 * fresh player with raw rows so the expected figures are known exactly rather
 * than re-derived from the fixtures with the same SQL.
 */
final class GetAccountDeletionSummaryTest extends KernelTestCase
{
    private GetAccountDeletionSummary $query;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetAccountDeletionSummary::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCountsTimesPiecesSecondsAndDistinctCollectionPuzzles(): void
    {
        $player = $this->seedPlayer();
        $playerId = $player->id->toString();

        /** @var int|string $pieces */
        $pieces = $this->connection->fetchOne('SELECT pieces_count FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_01]);
        $pieces = (int) $pieces;
        self::assertGreaterThan(0, $pieces);

        // Two results (solo + team) on the same puzzle, one owned by somebody else
        $this->insertSolvingTime($playerId, PuzzleFixture::PUZZLE_500_01, seconds: 3600, type: 'solo');
        $this->insertSolvingTime($playerId, PuzzleFixture::PUZZLE_500_01, seconds: 1800, type: 'team');
        $this->insertSolvingTime($this->seedPlayer()->id->toString(), PuzzleFixture::PUZZLE_500_01, seconds: 99999, type: 'solo');

        // Same puzzle in the system collection AND a custom collection = one puzzle;
        // a second puzzle in the system collection only
        $customCollectionId = Uuid::uuid7()->toString();
        $this->connection->insert('collection', [
            'id' => $customCollectionId,
            'player_id' => $playerId,
            'name' => 'Favourites',
            'visibility' => 'private',
            'created_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
        $this->insertCollectionItem($playerId, PuzzleFixture::PUZZLE_500_01, null);
        $this->insertCollectionItem($playerId, PuzzleFixture::PUZZLE_500_01, $customCollectionId);
        $this->insertCollectionItem($playerId, PuzzleFixture::PUZZLE_500_02, null);

        $summary = $this->query->byPlayerId($playerId);

        self::assertSame(2, $summary->solvingTimesCount);
        self::assertSame(2 * $pieces, $summary->totalPieces);
        self::assertSame(5400, $summary->totalSeconds);
        self::assertSame(2, $summary->collectionPuzzlesCount);
        self::assertFalse($summary->isEmpty());
    }

    public function testAFreshPlayerHasNothingToLose(): void
    {
        $summary = $this->query->byPlayerId($this->seedPlayer()->id->toString());

        self::assertSame(0, $summary->solvingTimesCount);
        self::assertSame(0, $summary->totalPieces);
        self::assertSame(0, $summary->totalSeconds);
        self::assertSame(0, $summary->collectionPuzzlesCount);
        self::assertTrue($summary->isEmpty());
    }

    private function seedPlayer(): Player
    {
        $userId = 'msp|' . bin2hex(random_bytes(4));
        $player = new Player(Uuid::uuid7(), 'SUM' . bin2hex(random_bytes(2)), $userId, $userId . '@example.com', 'Summary Tester', new DateTimeImmutable());

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function insertSolvingTime(string $playerId, string $puzzleId, int $seconds, string $type): void
    {
        $this->connection->insert('puzzle_solving_time', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'puzzle_id' => $puzzleId,
            'seconds_to_solve' => $seconds,
            'tracked_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'finished_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'verified' => 'true',
            'first_attempt' => 'false',
            'unboxed' => 'false',
            'puzzling_type' => $type,
        ]);
    }

    private function insertCollectionItem(string $playerId, string $puzzleId, null|string $collectionId): void
    {
        $this->connection->insert('collection_item', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'puzzle_id' => $puzzleId,
            'collection_id' => $collectionId,
            'added_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }
}
