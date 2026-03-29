<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecalculatePuzzleIntelligenceConsoleCommandTest extends KernelTestCase
{
    private PuzzleIntelligenceRecalculator $recalculator;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $this->recalculator = $recalculator;
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;
    }

    public function testRecalculateCreatesBaselines(): void
    {
        $result = $this->recalculator->recalculate();

        self::assertGreaterThan(0, $result['baselines'], 'Should create player baselines');
    }

    public function testRecalculateCreatesPuzzleDifficulty(): void
    {
        $result = $this->recalculator->recalculate();

        self::assertGreaterThan(0, $result['difficulties'], 'Should create puzzle difficulty scores');
    }

    public function testRecalculateCreatesSkillHistory(): void
    {
        $result = $this->recalculator->recalculate();

        self::assertGreaterThan(0, $result['history'], 'Should create skill history snapshots');
    }

    public function testRecalculateForSpecificPlayer(): void
    {
        $result = $this->recalculator->recalculate(specificPlayer: PlayerFixture::PLAYER_REGULAR);

        self::assertGreaterThan(0, $result['baselines']);

        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM player_baseline WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertGreaterThan(0, (int) $count);
    }

    public function testRecalculateIsIdempotent(): void
    {
        $this->recalculator->recalculate();
        $this->recalculator->recalculate();

        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM player_baseline WHERE player_id = :playerId AND pieces_count = 500',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertSame(1, (int) $count, 'Should have exactly one baseline per player per piece count');
    }

    public function testPuzzle50001HasDifficultyAfterRecalculation(): void
    {
        $this->recalculator->recalculate();

        /** @var array{difficulty_score: float|string|null, difficulty_tier: int|string|null, confidence: string, sample_size: int|string}|false $result */
        $result = $this->connection->fetchAssociative(
            'SELECT difficulty_score, difficulty_tier, confidence, sample_size FROM puzzle_difficulty WHERE puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_500_01],
        );

        self::assertNotFalse($result, 'PUZZLE_500_01 should have a difficulty record');
        self::assertNotNull($result['difficulty_score'], 'Should have a difficulty score');
        self::assertNotNull($result['difficulty_tier'], 'Should have a difficulty tier');
        self::assertNotSame('insufficient', $result['confidence']);
        self::assertGreaterThanOrEqual(5, (int) $result['sample_size']);
    }

    public function testBaselinesMatchExpectedRange(): void
    {
        $this->recalculator->recalculate();

        /** @var array{baseline_seconds: int|string}|false $result */
        $result = $this->connection->fetchAssociative(
            'SELECT baseline_seconds FROM player_baseline WHERE player_id = :playerId AND pieces_count = 500',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotFalse($result);

        $baseline = (int) $result['baseline_seconds'];

        // PLAYER_REGULAR's 500pc times: 1800, 2200, 1950, 1900, 1850 → baseline should be ~1900
        self::assertGreaterThan(1500, $baseline, 'Baseline should be > 25 min');
        self::assertLessThan(2500, $baseline, 'Baseline should be < 42 min');
    }
}
