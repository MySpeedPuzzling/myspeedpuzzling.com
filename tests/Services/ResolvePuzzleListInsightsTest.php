<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\ResolvePuzzleListInsights;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResolvePuzzleListInsightsTest extends KernelTestCase
{
    private ResolvePuzzleListInsights $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $this->resolver = self::getContainer()->get(ResolvePuzzleListInsights::class);
    }

    public function testMemberSeesTiers(): void
    {
        $member = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertTrue($member->activeMembership);

        $insights = $this->resolver->forViewer($member, [PuzzleFixture::PUZZLE_500_01]);

        self::assertTrue($insights->withDifficulty);
        self::assertNotNull($insights->byPuzzle[PuzzleFixture::PUZZLE_500_01]->difficultyTier);
        self::assertSame(['puzzle_insights' => $insights->byPuzzle, 'insights_with_difficulty' => true], $insights->templateParameters());
    }

    public function testNonMemberAndGuestGetSolveCountsOnly(): void
    {
        $nonMember = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($nonMember->activeMembership);

        foreach ([$nonMember, null] as $viewer) {
            $insights = $this->resolver->forViewer($viewer, [PuzzleFixture::PUZZLE_500_01]);

            self::assertFalse($insights->withDifficulty);
            self::assertNull($insights->byPuzzle[PuzzleFixture::PUZZLE_500_01]->difficultyTier);
            self::assertGreaterThan(0, $insights->byPuzzle[PuzzleFixture::PUZZLE_500_01]->solvedTimes);
        }
    }
}
