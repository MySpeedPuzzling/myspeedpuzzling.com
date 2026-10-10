<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Digest;

use SpeedPuzzling\Web\Results\WeeklyDigestData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Renders both weekly-digest variants end-to-end through Inky + inline CSS — the
 * equivalent of the "send test digest to Mailpit" acceptance check, DB-free.
 */
final class WeeklyDigestEmailRenderingTest extends KernelTestCase
{
    public function testMemberVariantWithActivityRenders(): void
    {
        $html = $this->render(memberVariant: true, data: $this->activityData());

        self::assertStringContainsString('+42 XP', $html);
        self::assertStringContainsString('4 solves — 2500 pieces placed.', $html);
        self::assertStringNotContainsString(']1,Inf[', $html, 'A plural message rendered raw');
        self::assertStringContainsString('Puzzle Explorer', $html);
        self::assertStringContainsString('unsubscribe', $html);
        self::assertStringContainsString('https://example.com/unsubscribe', $html);

        // Badges as 48 px PNGs at absolute URLs: the earned one and the one being worked toward
        self::assertMatchesRegularExpression('~<img src="https?://[^"/]+/img/badges/png/96/puzzles_solved-t2\.png\?v=[^"]+" alt="" width="48" height="48"~', $html);
        self::assertMatchesRegularExpression('~<img src="https?://[^"/]+/img/badges/png/96/streak-t3\.png\?v=[^"]+"~', $html);
    }

    public function testFreeTeaserVariantRenders(): void
    {
        $html = $this->render(memberVariant: false, data: $this->activityData());

        self::assertStringContainsString('waiting for you', $html);
        self::assertStringNotContainsString('Within your reach', $html);
        // Achievements are members-only - the teaser shows no badge
        self::assertStringNotContainsString('/img/badges/', $html);
    }

    public function testNoActivityVariantRenders(): void
    {
        $html = $this->render(memberVariant: true, data: $this->quietData());

        self::assertStringContainsString('a puzzle is always a good idea', $html);
        self::assertStringNotContainsString('Your week in numbers', $html);
    }

    private function render(bool $memberVariant, WeeklyDigestData $data): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('emails/content_digest_weekly.html.twig', [
            'playerName' => 'Testy',
            'data' => $data,
            'isMember' => $memberVariant,
            'hadActivity' => $data->hadActivity(),
            'unsubscribeUrl' => 'https://example.com/unsubscribe?signed=1',
            'locale' => 'en',
        ]);
    }

    private function activityData(): WeeklyDigestData
    {
        return new WeeklyDigestData(
            xpGained: 42,
            levelsGained: 1,
            currentLevel: 12,
            achievementsEarned: [
                ['type' => \SpeedPuzzling\Web\Value\BadgeType::PuzzlesSolved, 'tier' => \SpeedPuzzling\Web\Value\BadgeTier::Silver],
            ],
            solvesCount: 4,
            piecesCount: 2500,
            minutesSpent: 300,
            previousSolvesCount: 2,
            previousPiecesCount: 1000,
            currentStreakDays: 5,
            favoritesActivity: [['name' => 'Jane', 'solves' => 3]],
            nextAchievements: [
                ['type' => \SpeedPuzzling\Web\Value\BadgeType::Streak, 'progress' => new \SpeedPuzzling\Web\Results\BadgeProgress(\SpeedPuzzling\Web\Value\BadgeTier::Gold, 60, 90, 66)],
            ],
            mostSolvedPuzzleName: 'Colorful Cats',
            mostSolvedPuzzleSolvers: 12,
        );
    }

    private function quietData(): WeeklyDigestData
    {
        return new WeeklyDigestData(
            xpGained: 0,
            levelsGained: 0,
            currentLevel: 12,
            achievementsEarned: [],
            solvesCount: 0,
            piecesCount: 0,
            minutesSpent: 0,
            previousSolvesCount: 0,
            previousPiecesCount: 0,
            currentStreakDays: 0,
            favoritesActivity: [],
            nextAchievements: [],
            mostSolvedPuzzleName: null,
            mostSolvedPuzzleSolvers: 0,
        );
    }
}
