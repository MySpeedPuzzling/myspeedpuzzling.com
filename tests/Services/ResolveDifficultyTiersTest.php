<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\DifficultyTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResolveDifficultyTiersTest extends KernelTestCase
{
    public function testOnlyAMemberGetsTiers(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $resolver = $container->get(ResolveDifficultyTiers::class);
        $profiles = $container->get(GetPlayerProfile::class);
        $puzzles = [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_9000];

        $tiers = $resolver->forViewer($profiles->byId(PlayerFixture::PLAYER_WITH_STRIPE), $puzzles);
        self::assertNotNull($tiers);
        self::assertInstanceOf(DifficultyTier::class, $tiers[PuzzleFixture::PUZZLE_500_01] ?? null);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_9000, $tiers, 'Not rated yet = missing');

        self::assertNull($resolver->forViewer($profiles->byId(PlayerFixture::PLAYER_REGULAR), $puzzles));
        self::assertNull($resolver->forViewer(null, $puzzles));
    }

    public function testOnlyAMemberGetsRatings(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $resolver = $container->get(ResolveDifficultyTiers::class);
        $profiles = $container->get(GetPlayerProfile::class);
        $puzzles = [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_9000];

        $ratings = $resolver->ratingsForViewer($profiles->byId(PlayerFixture::PLAYER_WITH_STRIPE), $puzzles);
        self::assertNotNull($ratings);
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $ratings);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_9000, $ratings, 'Not rated yet = missing');

        self::assertNull($resolver->ratingsForViewer($profiles->byId(PlayerFixture::PLAYER_REGULAR), $puzzles));
        self::assertNull($resolver->ratingsForViewer(null, $puzzles));
    }
}
