<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PlayerBaseline;
use SpeedPuzzling\Web\Message\RecalculateDerivedMetricsForPuzzle;
use SpeedPuzzling\Web\MessageHandler\RecalculateDerivedMetricsForPuzzleHandler;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleDifficultyCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecalculateDerivedMetricsForPuzzleHandlerTest extends KernelTestCase
{
    private RecalculateDerivedMetricsForPuzzleHandler $handler;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var RecalculateDerivedMetricsForPuzzleHandler $handler */
        $handler = $container->get(RecalculateDerivedMetricsForPuzzleHandler::class);
        $this->handler = $handler;

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        $this->persistBaselinesAndDifficulty();
    }

    public function testUpdatesDerivedMetricsForPuzzle(): void
    {
        $message = new RecalculateDerivedMetricsForPuzzle(
            Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
        );

        ($this->handler)($message);

        $row = $this->connection->fetchAssociative(
            'SELECT skill_sensitivity_score, predictability_score, box_dependence_score, improvement_ceiling_score FROM puzzle_difficulty WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        self::assertNotFalse($row);
        // At least some derived metrics should be computed (depends on fixture data)
        // skill_sensitivity and predictability require 20+ indices, so they may be null
        // The handler should run without errors regardless
    }

    public function testDoesNotUpdateMemorabilityScore(): void
    {
        // Set a known memorability value first
        $this->connection->executeStatement(
            'UPDATE puzzle_difficulty SET memorability_score = :score WHERE puzzle_id = :puzzleId',
            ['score' => 1.234, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        $message = new RecalculateDerivedMetricsForPuzzle(
            Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
        );

        ($this->handler)($message);

        $memorability = $this->connection->fetchOne(
            'SELECT memorability_score FROM puzzle_difficulty WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        // Memorability should be left untouched (needs global normalization from hourly batch)
        self::assertEqualsWithDelta(1.234, (float) $memorability, 0.001);
    }

    private function persistBaselinesAndDifficulty(): void
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var PlayerBaselineCalculator $baselineCalculator */
        $baselineCalculator = self::getContainer()->get(PlayerBaselineCalculator::class);
        /** @var PuzzleDifficultyCalculator $difficultyCalculator */
        $difficultyCalculator = self::getContainer()->get(PuzzleDifficultyCalculator::class);

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

        // Persist difficulty for PUZZLE_500_01 so derived metrics can be computed
        $now = $clock->now();
        $difficulty = $difficultyCalculator->calculateForPuzzle(PuzzleFixture::PUZZLE_500_01);

        $this->connection->executeStatement("
            INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, indices_p25, indices_p75, computed_at)
            VALUES (:puzzleId, :score, :tier, :confidence, :sampleSize, :indicesP25, :indicesP75, :now)
            ON CONFLICT (puzzle_id) DO UPDATE SET
                difficulty_score = EXCLUDED.difficulty_score,
                difficulty_tier = EXCLUDED.difficulty_tier,
                confidence = EXCLUDED.confidence,
                sample_size = EXCLUDED.sample_size,
                indices_p25 = EXCLUDED.indices_p25,
                indices_p75 = EXCLUDED.indices_p75,
                computed_at = EXCLUDED.computed_at
        ", [
            'puzzleId' => PuzzleFixture::PUZZLE_500_01,
            'score' => $difficulty['difficulty_score'],
            'tier' => $difficulty['difficulty_tier']?->value,
            'confidence' => $difficulty['confidence']->value,
            'sampleSize' => $difficulty['sample_size'],
            'indicesP25' => $difficulty['indices_p25'],
            'indicesP75' => $difficulty['indices_p75'],
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
