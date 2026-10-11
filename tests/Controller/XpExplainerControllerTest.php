<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;
use SpeedPuzzling\Web\Value\XpAward;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The XP explainer is public since the launch (no feature flag).
 */
final class XpExplainerControllerTest extends WebTestCase
{
    public function testAnonymousVisitorSeesTheExplainer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('How XP &amp; Levels work', $content);
        self::assertStringNotContainsString('Achievements give XP', $content, 'Achievements grant Achievement Points only');
    }

    public function testSignedInPlayerSeesTheExplainer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/how-xp-works');

        self::assertResponseIsSuccessful();
    }

    /**
     * The difficulty table names every tier the way puzzles show it, with the bonus in % of the base that the formula
     * really adds (a 2000-piece puzzle: base 20 XP, so every rate is a whole number)
     */
    public function testDifficultyBonusOfEveryTierIsTheFormulas(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/how-xp-works');
        self::assertResponseIsSuccessful();

        $calculator = new XpCalculator();

        foreach (DifficultyTier::cases() as $tier) {
            $row = $crawler->filter('.xp-explainer-difficulty tr[data-difficulty-tier="' . $tier->value . '"]');
            self::assertCount(1, $row, 'a row for ' . $tier->name);

            $awards = self::amountsByReason($calculator->calculate(new SolveXpContext(
                piecesCount: 2000,
                difficultyTier: $tier->value,
                isTimed: true,
                isTeamOrDuo: false,
                unboxed: false,
                occurrenceIndex: 1,
                isBackfill: true,
            )));
            $percent = intdiv(100 * ($awards[XpReason::SolveDifficultyBonus->value] ?? 0), $awards[XpReason::SolveBase->value]);

            self::assertSame((string) $percent, $row->attr('data-bonus-percent'), $tier->name);
        }

        self::assertStringContainsString('Challenging', $crawler->filter('.xp-explainer-difficulty')->text());
        // Difficulty is named as puzzles show it, never as a number ("tier 4", "tiers 3-6")
        self::assertDoesNotMatchRegularExpression('/\btiers? ?-?\d/i', $crawler->filter('main')->text());
    }

    /**
     * Every line of the worked example is what the calculator awards for the solve the example describes: 1000 pieces,
     * Challenging, solo with a time, unboxed, faster than the median, one of the first 5 solves of the week
     */
    public function testWorkedExampleIsTheFormulas(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/how-xp-works');
        self::assertResponseIsSuccessful();

        $expected = self::amountsByReason((new XpCalculator())->calculate(new SolveXpContext(
            piecesCount: 1000,
            difficultyTier: DifficultyTier::Challenging->value,
            isTimed: true,
            isTeamOrDuo: false,
            unboxed: true,
            occurrenceIndex: 1,
            isBackfill: false,
            speedPercentile: SpeedPercentile::AboveMedian,
            xpEarningSolvesThisWeek: 0,
        )));

        $lineToReason = [
            'base' => XpReason::SolveBase->value,
            'difficulty' => XpReason::SolveDifficultyBonus->value,
            'unboxed' => XpReason::SolveUnboxedBonus->value,
            'speed' => XpReason::SolveSpeedBonus->value,
            'weekly' => XpReason::SolveWeeklyBoost->value,
        ];
        $shown = [];
        $crawler->filter('[data-xp-example-line]')->each(static function (Crawler $row) use (&$shown, $lineToReason): void {
            $line = (string) $row->attr('data-xp-example-line');
            self::assertArrayHasKey($line, $lineToReason, 'a line the formula knows');
            $shown[$lineToReason[$line]] = (int) $row->attr('data-xp');
        });

        ksort($expected);
        ksort($shown);
        self::assertSame($expected, $shown);
        self::assertSame((string) array_sum($expected), $crawler->filter('[data-xp-example-total]')->attr('data-xp-example-total'));
        self::assertStringContainsString(array_sum($expected) . ' XP', $crawler->filter('[data-xp-example-total]')->text());
    }

    /**
     * @param list<XpAward> $awards
     * @return array<string, int>
     */
    private static function amountsByReason(array $awards): array
    {
        $amounts = [];

        foreach ($awards as $award) {
            $amounts[$award->reason->value] = $award->amount;
        }

        return $amounts;
    }
}
