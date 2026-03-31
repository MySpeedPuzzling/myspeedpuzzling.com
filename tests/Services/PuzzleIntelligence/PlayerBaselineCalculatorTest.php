<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlayerBaselineCalculatorTest extends KernelTestCase
{
    private PlayerBaselineCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var PlayerBaselineCalculator $calculator */
        $calculator = $container->get(PlayerBaselineCalculator::class);
        $this->calculator = $calculator;
    }

    public function testCalculatesBaselineForPlayerWith500pcSolves(): void
    {
        // PLAYER_REGULAR has multiple 500pc first-attempt solo solves from fixtures
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertNotNull($result, 'PLAYER_REGULAR should have enough 500pc solves for a baseline');
        self::assertGreaterThan(0, $result['baseline_seconds']);
        self::assertGreaterThan(0, $result['qualifying_count']);

        // Baseline should be a reasonable 500pc time (between 20 and 60 minutes)
        self::assertGreaterThan(1200, $result['baseline_seconds']); // > 20 min
        self::assertLessThan(3600, $result['baseline_seconds']); // < 60 min
    }

    public function testReturnsNullForPiecesCountWithInsufficientData(): void
    {
        // 9000pc puzzles have no solving times at all
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 9000);

        self::assertNull($result, 'Should return null when no data exists for piece count');
    }

    public function testReturnsNullForNonExistentPlayer(): void
    {
        $result = $this->calculator->calculateForPlayer('00000000-0000-0000-0000-000000000099', 500);

        self::assertNull($result);
    }

    public function testAllPlayersHave500pcBaseline(): void
    {
        $players = [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PlayerFixture::PLAYER_WITH_STRIPE,
        ];

        foreach ($players as $playerId) {
            $result = $this->calculator->calculateForPlayer($playerId, 500);

            self::assertNotNull($result, "Player {$playerId} should have a 500pc baseline");
            self::assertGreaterThan(0, $result['baseline_seconds']);
        }
    }

    public function testFasterPlayerHasLowerBaseline(): void
    {
        // PLAYER_PRIVATE (player2) is generally faster on 500pc than PLAYER_WITH_FAVORITES (player4)
        $fastPlayer = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_PRIVATE, 500);
        $slowPlayer = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 500);

        self::assertNotNull($fastPlayer);
        self::assertNotNull($slowPlayer);
        self::assertLessThan($slowPlayer['baseline_seconds'], $fastPlayer['baseline_seconds']);
    }

    public function testDecayPlateauGivesFullWeightForRecentSolves(): void
    {
        // Both players have recent solves (within 3-month plateau), so baseline should be stable
        $result = $this->calculator->calculateForPlayer(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertNotNull($result);
        // The baseline should exist and be reasonable — the plateau doesn't prevent calculation
        self::assertGreaterThan(0, $result['baseline_seconds']);
    }
}
