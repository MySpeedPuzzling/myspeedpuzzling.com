<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PlayerBaseline;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\MessageHandler\RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChangeTest extends KernelTestCase
{
    private RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange $handler;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange $handler */
        $handler = $container->get(RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange::class);
        $this->handler = $handler;

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        $this->persistBaselines();
    }

    public function testUpdatesBaselineOnPuzzleSolved(): void
    {
        $event = new PuzzleSolved(
            Uuid::fromString(PuzzleIntelligenceFixture::INTEL_TIME_13),
            Uuid::fromString(PuzzleIntelligenceFixture::INTEL_PUZZLE_A),
        );

        ($this->handler)($event);

        /** @var array{baseline_seconds: string, qualifying_solves_count: string}|false $baseline */
        $baseline = $this->connection->fetchAssociative(
            'SELECT baseline_seconds, qualifying_solves_count FROM player_baseline WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'piecesCount' => 500],
        );

        self::assertNotFalse($baseline);
        self::assertGreaterThan(0, (int) $baseline['baseline_seconds']);
        self::assertGreaterThanOrEqual(5, (int) $baseline['qualifying_solves_count']);
    }

    public function testUpdatesDifficultyOnPuzzleSolved(): void
    {
        $event = new PuzzleSolved(
            Uuid::fromString(PuzzleIntelligenceFixture::INTEL_TIME_13),
            Uuid::fromString(PuzzleIntelligenceFixture::INTEL_PUZZLE_A),
        );

        ($this->handler)($event);

        /** @var array{difficulty_score: string|null, sample_size: string, confidence: string}|false $difficulty */
        $difficulty = $this->connection->fetchAssociative(
            'SELECT difficulty_score, sample_size, confidence FROM puzzle_difficulty WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleIntelligenceFixture::INTEL_PUZZLE_A],
        );

        self::assertNotFalse($difficulty);
        self::assertGreaterThanOrEqual(5, (int) $difficulty['sample_size']);
    }

    public function testUpdatesOnPuzzleSolvingTimeModified(): void
    {
        $event = new PuzzleSolvingTimeModified(
            Uuid::fromString(PuzzleIntelligenceFixture::INTEL_TIME_13),
            Uuid::fromString(PuzzleIntelligenceFixture::INTEL_PUZZLE_A),
        );

        ($this->handler)($event);

        /** @var array{baseline_seconds: string}|false $baseline */
        $baseline = $this->connection->fetchAssociative(
            'SELECT baseline_seconds FROM player_baseline WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'piecesCount' => 500],
        );

        self::assertNotFalse($baseline);
        self::assertGreaterThan(0, (int) $baseline['baseline_seconds']);
    }

    public function testUpdatesOnPuzzleSolvingTimeDeleted(): void
    {
        $event = new PuzzleSolvingTimeDeleted(
            Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
            Uuid::fromString(PlayerFixture::PLAYER_REGULAR),
            500,
        );

        ($this->handler)($event);

        /** @var array{baseline_seconds: string}|false $baseline */
        $baseline = $this->connection->fetchAssociative(
            'SELECT baseline_seconds FROM player_baseline WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'piecesCount' => 500],
        );

        self::assertNotFalse($baseline);
        self::assertGreaterThan(0, (int) $baseline['baseline_seconds']);
    }

    public function testSkipsNonSoloSolves(): void
    {
        /** @var string|false $duoTimeId */
        $duoTimeId = $this->connection->fetchOne("
            SELECT id FROM puzzle_solving_time WHERE puzzling_type != 'solo' LIMIT 1
        ");

        if ($duoTimeId === false) {
            self::markTestSkipped('No non-solo solving times in fixtures');
        }

        /** @var string $puzzleId */
        $puzzleId = $this->connection->fetchOne(
            'SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $duoTimeId],
        );

        /** @var string|int $beforeCountRaw */
        $beforeCountRaw = $this->connection->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty');
        $beforeCount = (int) $beforeCountRaw;

        $event = new PuzzleSolved(
            Uuid::fromString($duoTimeId),
            Uuid::fromString($puzzleId),
        );

        ($this->handler)($event);

        /** @var string|int $afterCountRaw */
        $afterCountRaw = $this->connection->fetchOne('SELECT COUNT(*) FROM puzzle_difficulty');
        $afterCount = (int) $afterCountRaw;

        self::assertSame($beforeCount, $afterCount);
    }

    public function testWritesDifficultyForPuzzleWithEnoughSolvers(): void
    {
        $event = new PuzzleSolved(
            Uuid::fromString(PuzzleSolvingTimeFixture::TIME_01),
            Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
        );

        ($this->handler)($event);

        /** @var array{difficulty_score: string|null}|false $difficulty */
        $difficulty = $this->connection->fetchAssociative(
            'SELECT difficulty_score FROM puzzle_difficulty WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        self::assertNotFalse($difficulty);
        self::assertNotNull($difficulty['difficulty_score']);
    }

    private function persistBaselines(): void
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var PlayerBaselineCalculator $baselineCalculator */
        $baselineCalculator = self::getContainer()->get(PlayerBaselineCalculator::class);

        $this->connection->executeStatement('DELETE FROM player_baseline');

        $playerIds = [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PlayerFixture::PLAYER_WITH_STRIPE,
        ];

        foreach ($playerIds as $playerId) {
            $result = $baselineCalculator->calculateForPlayer($playerId, 500);

            if ($result === null) {
                continue;
            }

            $player = $em->find(Player::class, Uuid::fromString($playerId));
            assert($player instanceof Player);

            $baseline = new PlayerBaseline(
                id: Uuid::uuid7(),
                player: $player,
                piecesCount: 500,
                baselineSeconds: $result['baseline_seconds'],
                qualifyingSolvesCount: $result['qualifying_count'],
                computedAt: $clock->now(),
            );

            $em->persist($baseline);
        }

        $em->flush();
    }
}
